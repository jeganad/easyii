<?php
namespace yii\easyii\modules\catalog\api;

use Yii;

use yii\data\ActiveDataProvider;
use yii\easyii\modules\catalog\models\ItemData;
use yii\easyii\widgets\Fancybox;
use yii\easyii\modules\catalog\models\Category;
use yii\easyii\modules\catalog\models\Item;
use yii\web\NotFoundHttpException;
use yii\widgets\LinkPager;

/**
 * Catalog module API
 * @package yii\easyii\modules\catalog\api
 *
 * @method static CategoryObject cat(mixed $id_slug) Get catalog category by id or slug
 * @method static array tree() Get catalog categories as tree
 * @method static array cats() Get catalog categories as flat array
 * @method static array items(array $options = []) Get list of items as ItemObject objects
 * @method static ItemObject get(mixed $id_slug) Get item object by id or slug
 * @method static mixed last(int $limit = 1, mixed $where = null) Get last items, use $where option for fetching items from special category
 * @method static void plugin() Applies FancyBox widget on photos called by box() function
 * @method static string pages() returns pagination html generated by yii\widgets\LinkPager widget.
 * @method static \stdClass pagination() returns yii\data\Pagination object.
 */

class Catalog extends \yii\easyii\components\API
{
    private $_cats;
    private $_adp;
    private $_item = [];

    public function api_cat($id_slug)
    {
        if(!isset($this->_cats[$id_slug])) {
            $this->_cats[$id_slug] = new CategoryObject(Category::get($id_slug));
        }
        return $this->_cats[$id_slug];
    }

    public function api_tree()
    {
        return Category::tree();
    }

    public function api_cats($options = [])
    {
        $result = [];
        foreach(Category::cats() as $model){
            $result[] = new CategoryObject($model);
        }
        if(!empty($options['tags'])){
            foreach($result as $i => $item){
                if(!in_array($options['tags'], $item->tags)){
                    unset($result[$i]);
                }
            }
        }

        return $result;
    }

    public function api_items($options = [])
    {
        $result = [];

        $query = Item::find()->with(['seo'])->status(Item::STATUS_ON);

        if(!empty($options['where'])){
            $query->andFilterWhere($options['where']);
        }
        if(!empty($options['orderBy'])){
            $query->orderBy($options['orderBy']);
        } else {
            $query->sortDate();
        }
        if(!empty($options['filters'])){
            $query = self::applyFilters($options['filters'], $query);
        }

        $this->_adp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => !empty($options['pagination']) ? $options['pagination'] : []
        ]);

        foreach($this->_adp->models as $model){
            $result[] = new ItemObject($model);
        }
        return $result;
    }

    public function api_last($limit = 1, $where = null)
    {
        $result = [];

        $query = Item::find()->with('seo')->sortDate()->status(Item::STATUS_ON)->limit($limit);
        if($where){
            $query->andFilterWhere($where);
        }

        foreach($query->all() as $item){
            $result[] = new ItemObject($item);
        }
        return $result;
    }

    public function api_get($id_slug)
    {
        if(!isset($this->_item[$id_slug])) {
            $this->_item[$id_slug] = $this->findItem($id_slug);
        }
        return $this->_item[$id_slug];
    }

    public function api_pagination()
    {
        return $this->_adp ? $this->_adp->pagination : null;
    }

    public function api_pages()
    {
        return $this->_adp ? LinkPager::widget(['pagination' => $this->_adp->pagination]) : '';
    }

    public function api_plugin($options = [])
    {
        Fancybox::widget([
            'selector' => '.easyii-box',
            'options' => $options
        ]);
    }
    
    public static function applyFilters($filters, $query)
    {
        if(is_array($filters)){

            if(!empty($filters['price'])){
                $price = $filters['price'];
                if(is_array($price) && count($price) == 2) {
                    if(!$price[0]){
                        $query->andFilterWhere(['<=', 'price * ( 1 - discount / 100 )', (int)$price[1]]);
                    } elseif(!$price[1]) {
                        $query->andFilterWhere(['>=', 'price * ( 1 - discount / 100 )', (int)$price[0]]);
                    } else {
                        $query->andFilterWhere(['between', 'price * ( 1 - discount / 100 )', (int)$price[0], (int)$price[1]]);
                    }
                }
                unset($filters['price']);
            }
            if(count($filters)){
                $filtersApplied = 0;
                $subQuery = ItemData::find()->select('item_id, COUNT(*) as filter_matched')->groupBy('item_id');
                foreach($filters as $field => $value){
                    if(!is_array($value)) {
                        $subQuery->orFilterWhere(['and', ['name' => $field], ['value' => $value]]);
                        $filtersApplied++;
                    } elseif(count($value) == 2){
                        if(!$value[0]){
                            $additionalCondition = ['<=', 'value', (int)$value[1]];
                        } elseif(!$value[1]) {
                            $additionalCondition = ['>=', 'value', (int)$value[0]];
                        } else {
                            $additionalCondition = ['between', 'value', (int)$value[0], (int)$value[1]];
                        }
                        $subQuery->orFilterWhere(['and', ['name' => $field], $additionalCondition]);

                        $filtersApplied++;
                    }
                }
                if($filtersApplied) {
                    $query->join('LEFT JOIN', ['f' => $subQuery], 'f.item_id = '.Item::tableName().'.item_id');
                    $query->andFilterWhere(['f.filter_matched' => $filtersApplied]);
                }
            }
        }
        return $query;
    }

    private function findItem($id_slug)
    {
        if(!($item = Item::find()->where(['or', 'id=:id_slug', 'slug=:id_slug'], [':id_slug' => $id_slug])->status(Item::STATUS_ON)->one())){
            throw new NotFoundHttpException(Yii::t('easyii', 'Not found'));
        }
        return new ItemObject($item);
    }
}