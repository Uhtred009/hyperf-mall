<?php
/**
 * Created by PhpStorm.
 * User: 简美
 * Date: 2020/4/10
 * Time: 10:32
 */

namespace App\Services;

use App\Model\CrowdfundingProduct;
use App\Model\Product;
use App\Model\ProductSku;
use App\SearchBuilders\ProductSearchBuilder;
use App\Utils\ElasticSearch;
use Hyperf\DbConnection\Db;

class ProductService
{
    public function createProduct($productData): Product
    {
        return Db::transaction(function () use ($productData)
        {
            $category_id = $productData['category_id'] ?? null;
            $productAttributes = [
                'title' => $productData['title'],
                'description' => $productData['description'],
                'image' => $productData['image'],
                'on_sale' => $productData['on_sale'],
                'price' => $productData['price'],
                'category_id' => $category_id
            ];


            $product = new Product($productAttributes);
            $product->save();

            $properties = $productData['properties'] ?? null;
            foreach ($properties as $property)
            {
                $productProperty = $product->properties()->make($property);
                $productProperty->save();
            }

            foreach ($productData['items'] as $sku)
            {
                /** @var $productSku ProductSku */
                $productSku = $product->skus()->make($sku);
                $productSku->product()->associate($product);
                $productSku->save();
            }

            if (key_exists('target_amount', $productData))
            {
                $crowdfunding = new CrowdfundingProduct();
                $crowdfunding->target_amount = $productData['target_amount'];
                $crowdfunding->end_time = $productData['end_time'];
                $crowdfunding->product()->associate($product);
                $crowdfunding->save();
                $product->type = Product::TYPE_CROWDFUNDING;
                $product->save();
            }
            return $product;
        });

    }

    /**
     * 获取相似商品
     * @param Product $product
     * @param int $pageSize
     * @return array
     */
    public function getSimilarProductIds(Product $product, int $pageSize)
    {
        $es = container()->get(ElasticSearch::class);
        // 如果商品没有商品属性，则直接返回空
        if (count($product->properties) === 0)
        {
            return [];
        }
        $builder = (new ProductSearchBuilder())->onSale()->paginate(1, $pageSize);
        foreach ($product->properties as $property)
        {
            $builder->propertyFilter($property->name . ':' . $property->value, 'should');
        }
        $builder->minShouldMatch(ceil(count($product->properties) / 2));
        $params = $builder->getParams();
        $params['body']['query']['bool']['must_not'] = [['term' => ['_id' => $product->id]]];
        $result = $es->es_client->search($params);

        return collect($result['hits']['hits'])->pluck('_id')->all();
    }
}