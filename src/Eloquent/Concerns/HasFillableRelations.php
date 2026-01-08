<?php

namespace LaravelFillableRelations\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionObject;
use RuntimeException;

/**
 * Mix this in to your model class to enable fillable relations.
 * Usage:
 *     use Illuminate\Database\Eloquent\Model;
 *     use LaravelFillableRelations\Eloquent\Concerns\HasFillableRelations;
 *
 *     class Foo extends Model
 *     {
 *         use HasFillableRelations;
 *         protected $fillable_relations = ['bar'];
 *
 *         function bar()
 *         {
 *             return $this->hasOne(Bar::class);
 *         }
 *     }
 *
 *     $foo = new Foo(['bar' => ['id' => 42]]);
 *     // or perhaps:
 *     $foo = new Foo(['bar' => ['name' => "Ye Olde Pubbe"]]);
 *
 * @mixin Model
 */
trait HasFillableRelations
{
    ///**
    // * The relations that should be mass assignable.
    // *
    // * @var array
    // */
    // protected $fillable_relations = [];

    public function fillableRelations()
    {
        return isset($this->fillable_relations) ? $this->fillable_relations : [];
    }

    public function extractFillableRelations(array $attributes)
    {
        $relationsAttributes = [];

        foreach ($this->fillableRelations() as $relationName) {
            $val = Arr::pull($attributes, $relationName);
            if ($val !== null) {
                $relationsAttributes[$relationName] = $val;
            }
        }

        return [$relationsAttributes, $attributes];
    }

    public function fillRelations(array $relations)
    {
        foreach ($relations as $relationName => $attributes) {
            $relation = $this->{Str::camel($relationName)}();

            $relationType = (new ReflectionObject($relation))->getShortName();
            $method = "fill{$relationType}Relation";
            if (!method_exists($this, $method)) {
                throw new RuntimeException("Unknown or unfillable relation type {$relationType} ({$relationName})");
            }
            $this->{$method}($relation, $attributes, $relationName);
        }
    }

    public function fill(array $attributes)
    {
        list($relations, $attributes) = $this->extractFillableRelations($attributes);

        parent::fill($attributes);

        $this->fillRelations($relations);

        return $this;
    }

    public static function create(array $attributes = [])
    {
        list($relations, $attributes) = (new static)->extractFillableRelations($attributes);

        $model = new static($attributes);
        $model->fillRelations($relations);
        $model->save();

        return $model;
    }

    /**
     * @param BelongsTo $relation
     * @param array|Model $attributes
     */
    public function fillBelongsToRelation(BelongsTo $relation, $attributes, $relationName)
    {
        if (!$attributes instanceof Model) {
            $related = $relation->getRelated();
            if(array_key_exists($related->getKeyName(), $attributes)){
                $relatedInstance = $related->find($attributes[$related->getKeyName()]);
                if($relatedInstance){
                    $relatedInstance = $related->update($attributes);
                }
            }else{
                $relatedInstance = $relation->getRelated()->create($attributes);
            }
        }else{
            $relatedInstance = $attributes;
            $relatedInstance->save();
        }
        $relation->associate($relatedInstance);
    }

    /**
     * @param HasOne $relation
     * @param array|Model $attributes
     *
     * Atomic版: 既存レコード更新時にロックを取得
     * 注意: 呼び出し元でDB::transaction()を使用すること
     */
    public function fillHasOneRelation(HasOne $relation, $attributes, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{Str::camel($relationName)}();
        }

        $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();
        // ロック付きで既存レコードを取得（race condition防止）
        $relatedInstance = $relation->lockForUpdate()->first();
        if ($relatedInstance?->exists) {
            $relatedInstance->update($attributes);
        } else {
            $relation->getRelated()->newInstance($attributes)->save();
        }
    }

    /**
     * @param HasMany $relation
     * @param array $attributes
     *
     * Atomic版: 全削除→再作成パターンを廃止し、安全なupsert/delete方式に変更
     * 注意: 呼び出し元でDB::transaction()を使用すること
     */
    public function fillHasManyRelation(HasMany $relation, array $attributesList, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{Str::camel($relationName)}();
        }

        $related = $relation->getRelated();
        $primaryKey = $related->getKeyName();
        $existingIds = [];

        foreach ($attributesList as $attributes) {
            if (!$attributes instanceof Model) {
                if (method_exists($relation, 'getHasCompareKey')) { // Laravel 5.3
                    $foreign_key = explode('.', $relation->getHasCompareKey());
                    $attributes[$foreign_key[1]] = $relation->getParent()->getKey();
                } else {  // Laravel 5.5+
                    $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();
                }

                if (array_key_exists($primaryKey, $attributes)) {
                    // 既存レコードをロック付きで更新（race condition防止）
                    if (method_exists($related, 'findForUpdate')) {
                        $relatedInstance = $related::findForUpdate($attributes[$primaryKey]);
                    } else {
                        $relatedInstance = $related::lockForUpdate()->find($attributes[$primaryKey]);
                    }
                    if ($relatedInstance) {
                        $relatedInstance->update($attributes);
                        $existingIds[] = $attributes[$primaryKey];
                    }
                } else {
                    // 新規レコードを作成
                    $relatedInstance = $related->create($attributes);
                    $existingIds[] = $relatedInstance->$primaryKey;
                }
            } else {
                $relatedInstance = $attributes;
                $relatedInstance->save();
                $existingIds[] = $relatedInstance->$primaryKey;
            }
        }

        // 更新/作成されなかったレコードのみを削除（安全な差分削除）
        $relation->whereNotIn($primaryKey, $existingIds)->delete();
    }

    /**
     * @param BelongsToMany $relation
     * @param array $attributes
     */
    #TODO: fix if required
    public function fillBelongsToManyRelation(BelongsToMany $relation, array $attributes, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{Str::camel($relationName)}();
        }

        $relation->detach();
        $pivotColumns = [];
        foreach ($attributes as $related) {
            if (isset($related['pivot']) && is_array($related['pivot'])) {
                $pivotColumns = $related['pivot'];
                unset($related['pivot']);
            }
            if (!$related instanceof Model) {
                $related = $relation->getRelated()
                    ->where($related)->firstOrFail();
            }

            $relation->attach($related, $pivotColumns);
        }
    }

    /**
     * @param MorphTo $relation
     * @param array|Model $attributes
     */
    public function fillMorphToRelation(MorphTo $relation, $attributes, $relationName)
    {
        $relatedInstance = $relation->getResults();
        if($relatedInstance?->exists){
            $relatedInstance->update($attributes);
        }else{
            $related = $relation->getRelated();
            if(array_key_exists($related->getKeyName(), $attributes)){
                $relatedInstance = $related->find($attributes[$related->getKeyName()]);
                $relatedInstance->update($attributes);
            }else{
                $relatedInstance = $relation->getRelated()->newInstance($attributes)->save();
            }
        }
        $relation->associate($relatedInstance);
    }

    /**
     * @param MorphOne $relation
     * @param array|Model $attributes
     *
     * Atomic版: 既存レコード更新時にロックを取得
     * 注意: 呼び出し元でDB::transaction()を使用すること
     */
    public function fillMorphOneRelation(MorphOne $relation, $attributes, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{Str::camel($relationName)}();
        }

        $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();
        $attributes[$relation->getMorphType()] = $relation->getMorphClass();
        // ロック付きで既存レコードを取得（race condition防止）
        $relatedInstance = $relation->lockForUpdate()->first();
        if ($relatedInstance?->exists) {
            $relatedInstance->update($attributes);
        } else {
            $relation->getRelated()->newInstance($attributes)->save();
        }
    }

    /**
     * @param MorphMany $relation
     * @param array $attributes
     *
     * Atomic版: 全削除→再作成パターンを廃止し、安全なupsert/delete方式に変更
     * 注意: 呼び出し元でDB::transaction()を使用すること
     */
    public function fillMorphManyRelation(MorphMany $relation, array $attributesList, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{Str::camel($relationName)}();
        }

        $related = $relation->getRelated();
        $primaryKey = $related->getKeyName();
        $existingIds = [];

        foreach ($attributesList as $attributes) {
            if (!$attributes instanceof Model) {
                if (method_exists($relation, 'getHasCompareKey')) { // Laravel 5.3
                    $foreign_key = explode('.', $relation->getHasCompareKey());
                    $attributes[$foreign_key[1]] = $relation->getParent()->getKey();
                } else {  // Laravel 5.5+
                    $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();
                }
                $attributes[$relation->getMorphType()] = $relation->getMorphClass();

                if (array_key_exists($primaryKey, $attributes)) {
                    // 既存レコードをロック付きで更新（race condition防止）
                    if (method_exists($related, 'findForUpdate')) {
                        $relatedInstance = $related::findForUpdate($attributes[$primaryKey]);
                    } else {
                        $relatedInstance = $related::lockForUpdate()->find($attributes[$primaryKey]);
                    }
                    if ($relatedInstance) {
                        $relatedInstance->update($attributes);
                        $existingIds[] = $attributes[$primaryKey];
                    }
                } else {
                    // 新規レコードを作成
                    $relatedInstance = $related->create($attributes);
                    $existingIds[] = $relatedInstance->$primaryKey;
                }
            } else {
                $relatedInstance = $attributes;
                $relatedInstance->save();
                $existingIds[] = $relatedInstance->$primaryKey;
            }
        }

        // 更新/作成されなかったレコードのみを削除（安全な差分削除）
        $relation->whereNotIn($primaryKey, $existingIds)->delete();
    }
}
