<?php
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];
$productModel = new Product(db());

if ($method === 'GET') {
    if ($id || isset($_GET['slug'])) {
        $identifier = $id ?: $_GET['slug'];
        if (is_numeric($identifier)) {
            $product = $productModel->getById($identifier);
        } else {
            $product = $productModel->getBySlug($identifier);
        }

        if ($product) {
            $product['display_image'] = asset_url(product_image($product));
            
            $hasVariants = !empty($product['has_variants']);
            $variants = $hasVariants ? get_product_variants((int)$product['id']) : [];
            $colorGalleries = json_decode($product['color_galleries'] ?? 'null', true) ?: [];
            
            $recommendations = get_products([
                'category_id' => $product['category_id'],
                'exclude_id'  => $product['id'],
                'limit'       => 4
            ]);
            
            // Transform images in recommendations
            foreach ($recommendations as &$rec) {
                $rec['display_image'] = asset_url(product_image($rec));
            }

            echo json_encode([
                'status' => 200, 
                'data' => [
                    'product' => $product,
                    'variants' => $variants,
                    'color_galleries' => $colorGalleries,
                    'recommendations' => $recommendations
                ]
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 404, 'error' => 'Product not found']);
        }
    } else {
        $categoryId = $_GET['category'] ?? null;
        $products = $productModel->getAll($categoryId);
        
        // Transform images
        foreach ($products as &$p) {
            $p['display_image'] = asset_url(product_image($p));
        }
        
        echo json_encode(['status' => 200, 'data' => $products]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 405, 'error' => 'Method not allowed']);
}
