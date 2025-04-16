<?php
// Helper functions for the Etsy clone

// Function to sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Function to generate a random string
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// Function to format currency
function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

// Function to get user by ID
function getUserById($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return null;
    }
}

// Function to check if user is a seller
function isSeller($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT is_seller FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user && $user['is_seller'] == 1;
    } catch(PDOException $e) {
        return false;
    }
}

// Function to get product reviews
function getProductReviews($db, $product_id) {
    try {
        $stmt = $db->prepare("SELECT r.*, u.username 
                              FROM reviews r 
                              JOIN users u ON r.user_id = u.id 
                              WHERE r.product_id = ? 
                              ORDER BY r.created_at DESC");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get average rating for a product
function getProductRating($db, $product_id) {
    try {
        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as review_count 
                              FROM reviews 
                              WHERE product_id = ?");
        $stmt->execute([$product_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return ['avg_rating' => 0, 'review_count' => 0];
    }
}

// Function to check if user has purchased a product (for review eligibility)
function hasUserPurchasedProduct($db, $user_id, $product_id) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count 
                              FROM orders o 
                              JOIN order_items oi ON o.id = oi.order_id 
                              WHERE o.user_id = ? AND oi.product_id = ? AND o.status != 'cancelled'");
        $stmt->execute([$user_id, $product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['count'] > 0;
    } catch(PDOException $e) {
        return false;
    }
}

// Function to get related products
function getRelatedProducts($db, $product_id, $category_id, $limit = 4) {
    try {
        $stmt = $db->prepare("SELECT p.*, c.name as category_name, u.username as seller_name 
                              FROM products p 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              LEFT JOIN users u ON p.seller_id = u.id 
                              WHERE p.category_id = ? AND p.id != ? 
                              ORDER BY RAND() 
                              LIMIT ?");
        $stmt->execute([$category_id, $product_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get featured products
function getFeaturedProducts($db, $limit = 8) {
    try {
        $stmt = $db->prepare("SELECT p.*, c.name as category_name, u.username as seller_name 
                              FROM products p 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              LEFT JOIN users u ON p.seller_id = u.id 
                              WHERE p.is_featured = 1 
                              ORDER BY RAND() 
                              LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get newest products
function getNewestProducts($db, $limit = 8) {
    try {
        $stmt = $db->prepare("SELECT p.*, c.name as category_name, u.username as seller_name 
                              FROM products p 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              LEFT JOIN users u ON p.seller_id = u.id 
                              ORDER BY p.created_at DESC 
                              LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get order details
function getOrderDetails($db, $order_id) {
    try {
        $stmt = $db->prepare("SELECT o.*, u.username 
                              FROM orders o 
                              JOIN users u ON o.user_id = u.id 
                              WHERE o.order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            $stmt = $db->prepare("SELECT oi.*, p.name, p.image 
                                  FROM order_items oi 
                                  JOIN products p ON oi.product_id = p.id 
                                  WHERE oi.order_id = ?");
            $stmt->execute([$order['id']]);
            $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $order;
    } catch(PDOException $e) {
        return null;
    }
}
?>
