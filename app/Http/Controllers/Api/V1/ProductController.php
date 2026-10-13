<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use App\Helpers\{
    FileHelper,
    ResponseHelper
};
use App\Http\Resources\Api\V1\Product\{
    ProductCollectionResource,
    ProductResource,
};
use App\Http\Requests\Api\V1\Product\{
    CreateProductRequest,
    EditProductRequest,
};

/**
 * @OA\Schema(
 *     schema="Product",
 *     type="object",
 *     title="Product",
 *     required={"id","name","price"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Sample Product"),
 *     @OA\Property(property="price", type="number", format="float", example=199.99),
 *     @OA\Property(property="description", type="string", example="This is a sample product."),
 *     @OA\Property(property="image_url", type="string", format="url", nullable=true, example="https://example.com/image.jpg"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-02T05:14:07.000000Z")
 * )
 */
class ProductController extends Controller
{
    /**
     * List all products
     *
     * @OA\Get(
     *     path="/products",
     *     summary="Get list of products",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of products",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Products retrieved successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Product"))
     *         )
     *     )
     * )
     */
    /**
     * Drop the cached catalogues.
     *
     * Both list endpoints are `Cache::remember(…, now()->addDays(1))` and
     * nothing ever invalidated them. So a price change, a new product, a stock
     * correction or an MRP took up to twenty-four hours to reach a rep's
     * phone — and the admin's own grid is served from the same cache, so the
     * person who made the edit could not see it either.
     *
     * Called from every write below. The keys are listed here rather than at
     * each call site so a third list cannot be added and quietly forgotten.
     */
    private function forgetCachedCatalogue(): void
    {
        Cache::forget('products');    // GET /products      — the admin grid
        Cache::forget('productList'); // GET /product-lists — the SR app
    }

    public function index()
    {
        if (Cache::has('products')) {
            return ResponseHelper::success(ProductCollectionResource::collection(Cache::get('products')), 'Products retrieved from cache successfully');
        } else {
            $products = Cache::remember('products', now()->addDays(1), function () {
                return Product::with('category')->orderBy('id', 'desc')->get();
            });
            return ResponseHelper::success(ProductCollectionResource::collection($products), 'Products retrieved successfully');
        }
    }

    public function getProducts()
    {
        if (Cache::has('productList')) {
            return ResponseHelper::success(ProductCollectionResource::collection(Cache::get('productList')), 'Products retrieved from cache successfully');
        } else {
            $products = Cache::remember('productList', now()->addDays(1), function () {
                return Product::where('status', 'active')->with('category')->orderBy('id', 'desc')->get();
            });
            return ResponseHelper::success(ProductCollectionResource::collection($products), 'Products retrieved successfully');
        }
    }

    /**
     * Create a new product
     *
     * @OA\Post(
     *     path="/products",
     *     summary="Create a product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name","price"},
     *                 @OA\Property(property="name", type="string", example="Sample Product"),
     *                 @OA\Property(property="price", type="number", example=199.99),
     *                 @OA\Property(property="description", type="string", example="Product description"),
     *                 @OA\Property(property="image", type="string", format="binary", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Product created successfully")
     * )
     */
    public function store(CreateProductRequest $request)
    {
        try {
            $productData = $request->validated();

            // Handle thumbnail upload (new product)
            if ($request->hasFile('thumbnail')) {
                $productData['thumbnail'] = FileHelper::uploadImages(
                    $request->file('thumbnail'),
                    'products',
                    ['optimize' => true]
                );
            }

            // Merge remaining fields
            $productData = array_merge($productData, [
                'sku' => generate_sku('PROD'),
                'name' => $request->name,
                'short_description' => $request->shortDescription,
                'purchase_price' => $request->purchasePrice,
                'sale_price' => $request->salePrice,
                'mrp' => $request->mrp,
                'stock' => $request->stock,
                'slug' => slugify($request->name ?? "prod"),
                'category_id' => $request->categoryId,
                'status' => $request->status,
            ]);

            $product = Product::create($productData);

            $this->forgetCachedCatalogue();

            return ResponseHelper::success(new ProductResource($product), 'Product created successfully', 201);

        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    /**
     * Get a single product
     *
     * @OA\Get(
     *     path="/products/{id}",
     *     summary="Get a product by ID",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Product retrieved successfully"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function show($id)
    {
        $product = Product::find($id);
        if (!$product) return ResponseHelper::error('Product not found', 404);
        return ResponseHelper::success(new ProductResource($product), 'Product retrieved successfully');
    }

    /**
     * Update a product
     *
     * @OA\Patch(
     *     path="/products/{id}",
     *     summary="Update a product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="price", type="number"),
     *                 @OA\Property(property="description", type="string"),
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Product updated successfully"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function update(EditProductRequest $request, $id)
    {
        try {
            $product = Product::find($id);
            if (!$product) return ResponseHelper::error('Product not found', 404);

            $productData = $request->validated();

            // Update thumbnail if provided (use updateImage)
            if ($request->hasFile('thumbnail')) {
                $productData['thumbnail'] = FileHelper::updateImage(
                    $request->file('thumbnail'),
                    $product->thumbnail,
                    'products',
                    ['optimize' => true]
                );
            }

            // Merge remaining fields
            $productData = array_merge($productData, [
                'name' => $request->name,
                'short_description' => $request->shortDescription,
                'purchase_price' => $request->purchasePrice,
                'sale_price' => $request->salePrice,
                'mrp' => $request->mrp,
                'stock' => $request->stock,
                'category_id' => $request->categoryId,
                'status' => $request->status,
            ]);

            $product->update($productData);

            $this->forgetCachedCatalogue();

            return ResponseHelper::success(new ProductResource($product), 'Product updated successfully');

        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    /**
     * Delete a product
     *
     * @OA\Delete(
     *     path="/products/{id}",
     *     summary="Delete a product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Product deleted successfully"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function destroy($id)
    {
        $product = Product::find($id);
        if (!$product) return ResponseHelper::error('Product not found', 404);

        $product->delete();

        $this->forgetCachedCatalogue();

        return ResponseHelper::success('Product deleted successfully');
    }
}
