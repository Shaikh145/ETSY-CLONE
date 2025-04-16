<?php
// Start session for user authentication
session_start();

// Include database connection
require_once 'db.php';

// Initialize variables
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$error = '';
$success = '';

// Handle user registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $is_seller = isset($_POST['is_seller']) ? 1 : 0;
    
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password, is_seller) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $email, $password, $is_seller]);
        
        if ($is_seller) {
            $user_id = $db->lastInsertId();
            $shop_name = $username . "'s Shop";
            $stmt = $db->prepare("INSERT INTO shop_settings (user_id, shop_name) VALUES (?, ?)");
            $stmt->execute([$user_id, $shop_name]);
        }
        
        $success = "Registration successful! Please log in.";
    } catch(PDOException $e) {
        $error = "Registration failed: " . $e->getMessage();
    }
}

// Handle user login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_seller'] = $user['is_seller'];
            $success = "Login successful!";
        } else {
            $error = "Invalid email or password.";
        }
    } catch(PDOException $e) {
        $error = "Login failed: " . $e->getMessage();
    }
}

// Handle product listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    if (!isset($_SESSION['user_id'])) {
        $error = "You must be logged in to add a product.";
    } else {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        $category_id = $_POST['category'];
        $stock = $_POST['stock'];
        $seller_id = $_SESSION['user_id'];
        
        // Handle image upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $upload_dir = 'uploads/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $image_name = time() . '_' . $_FILES['image']['name'];
            $upload_path = $upload_dir . $image_name;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $image = $upload_path;
            } else {
                $error = "Failed to upload image.";
            }
        }
        
        if (!$error) {
            try {
                $stmt = $db->prepare("INSERT INTO products (name, description, price, category_id, image, seller_id, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $price, $category_id, $image, $seller_id, $stock]);
                $success = "Product added successfully!";
            } catch(PDOException $e) {
                $error = "Failed to add product: " . $e->getMessage();
            }
        }
    }
}

// Handle adding to cart
if (isset($_GET['add_to_cart']) && isset($_GET['product_id'])) {
    $product_id = $_GET['product_id'];
    
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]++;
    } else {
        $_SESSION['cart'][$product_id] = 1;
    }
    
    $success = "Product added to cart!";
}

// Handle updating cart
if (isset($_GET['update_cart']) && isset($_GET['product_id']) && isset($_GET['quantity'])) {
    $product_id = $_GET['product_id'];
    $quantity = (int)$_GET['quantity'];
    
    if ($quantity > 0) {
        $_SESSION['cart'][$product_id] = $quantity;
    } else {
        unset($_SESSION['cart'][$product_id]);
    }
}

// Handle removing from cart
if (isset($_GET['remove_from_cart']) && isset($_GET['product_id'])) {
    $product_id = $_GET['product_id'];
    
    if (isset($_SESSION['cart'][$product_id])) {
        unset($_SESSION['cart'][$product_id]);
    }
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    if (!isset($_SESSION['user_id'])) {
        $error = "You must be logged in to checkout.";
    } else if (!isset($_SESSION['cart']) || count($_SESSION['cart']) === 0) {
        $error = "Your cart is empty.";
    } else {
        $user_id = $_SESSION['user_id'];
        $total_amount = $_POST['total_amount'];
        $shipping_address = $_POST['address'];
        $city = $_POST['city'];
        $zip_code = $_POST['zip'];
        $order_id = 'ORD' . time();
        
        try {
            // Begin transaction
            $db->beginTransaction();
            
            // Create order
            $stmt = $db->prepare("INSERT INTO orders (order_id, user_id, total_amount, shipping_address, city, zip_code) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $user_id, $total_amount, $shipping_address, $city, $zip_code]);
            $order_db_id = $db->lastInsertId();
            
            // Add order items
            $cart = getCartItems($db);
            foreach ($cart['items'] as $item) {
                $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$order_db_id, $item['id'], $item['quantity'], $item['price']]);
                
                // Update product stock
                $stmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['id']]);
            }
            
            // Commit transaction
            $db->commit();
            
            // Clear cart after successful checkout
            $_SESSION['cart'] = [];
            $success = "Order placed successfully! Order ID: " . $order_id;
            
            // Redirect to order confirmation page
            header("Location: index.php?page=order_confirmation&order_id=" . $order_id);
            exit();
        } catch(PDOException $e) {
            // Rollback transaction on error
            $db->rollBack();
            $error = "Checkout failed: " . $e->getMessage();
        }
    }
}

// Handle shop settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shop'])) {
    if (!isset($_SESSION['user_id'])) {
        $error = "You must be logged in to update shop settings.";
    } else {
        $user_id = $_SESSION['user_id'];
        $shop_name = $_POST['shop_name'];
        $shop_description = $_POST['shop_description'];
        
        // Handle shop banner upload
        $shop_banner = null;
        if (isset($_FILES['shop_banner']) && $_FILES['shop_banner']['error'] === 0) {
            $upload_dir = 'uploads/shop/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $banner_name = 'banner_' . $user_id . '_' . time() . '_' . $_FILES['shop_banner']['name'];
            $upload_path = $upload_dir . $banner_name;
            
            if (move_uploaded_file($_FILES['shop_banner']['tmp_name'], $upload_path)) {
                $shop_banner = $upload_path;
            } else {
                $error = "Failed to upload shop banner.";
            }
        }
        
        // Handle shop logo upload
        $shop_logo = null;
        if (isset($_FILES['shop_logo']) && $_FILES['shop_logo']['error'] === 0) {
            $upload_dir = 'uploads/shop/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $logo_name = 'logo_' . $user_id . '_' . time() . '_' . $_FILES['shop_logo']['name'];
            $upload_path = $upload_dir . $logo_name;
            
            if (move_uploaded_file($_FILES['shop_logo']['tmp_name'], $upload_path)) {
                $shop_logo = $upload_path;
            } else {
                $error = "Failed to upload shop logo.";
            }
        }
        
        if (!$error) {
            try {
                // Check if shop settings exist
                $stmt = $db->prepare("SELECT * FROM shop_settings WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $shop = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($shop) {
                    // Update existing shop settings
                    $sql = "UPDATE shop_settings SET shop_name = ?, shop_description = ?";
                    $params = [$shop_name, $shop_description];
                    
                    if ($shop_banner) {
                        $sql .= ", shop_banner = ?";
                        $params[] = $shop_banner;
                    }
                    
                    if ($shop_logo) {
                        $sql .= ", shop_logo = ?";
                        $params[] = $shop_logo;
                    }
                    
                    $sql .= " WHERE user_id = ?";
                    $params[] = $user_id;
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } else {
                    // Create new shop settings
                    $stmt = $db->prepare("INSERT INTO shop_settings (user_id, shop_name, shop_description, shop_banner, shop_logo) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $shop_name, $shop_description, $shop_banner, $shop_logo]);
                }
                
                // Update user to be a seller if not already
                $stmt = $db->prepare("UPDATE users SET is_seller = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                
                $_SESSION['is_seller'] = 1;
                $success = "Shop settings updated successfully!";
            } catch(PDOException $e) {
                $error = "Failed to update shop settings: " . $e->getMessage();
            }
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Function to get products
function getProducts($db, $category = null, $search = null, $seller_id = null, $limit = null) {
    $sql = "SELECT p.*, c.name as category_name, u.username as seller_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN users u ON p.seller_id = u.id 
            WHERE 1=1";
    $params = [];
    
    if ($category) {
        $sql .= " AND c.slug = ?";
        $params[] = $category;
    }
    
    if ($search) {
        $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($seller_id) {
        $sql .= " AND p.seller_id = ?";
        $params[] = $seller_id;
    }
    
    $sql .= " ORDER BY p.created_at DESC";
    
    if ($limit) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching products: " . $e->getMessage();
        return [];
    }
}

// Function to get product details
function getProduct($db, $id) {
    try {
        $stmt = $db->prepare("SELECT p.*, c.name as category_name, u.username as seller_name 
                              FROM products p 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              LEFT JOIN users u ON p.seller_id = u.id 
                              WHERE p.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching product: " . $e->getMessage();
        return null;
    }
}

// Function to get cart items
function getCartItems($db) {
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        return ['items' => [], 'total' => 0];
    }
    
    $items = [];
    $total = 0;
    
    foreach ($_SESSION['cart'] as $product_id => $quantity) {
        $product = getProduct($db, $product_id);
        if ($product) {
            $product['quantity'] = $quantity;
            $product['subtotal'] = $quantity * $product['price'];
            $total += $product['subtotal'];
            $items[] = $product;
        }
    }
    
    return ['items' => $items, 'total' => $total];
}

// Function to get categories
function getCategories($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM categories ORDER BY name");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching categories: " . $e->getMessage();
        return [];
    }
}

// Function to get shop settings
function getShopSettings($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM shop_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching shop settings: " . $e->getMessage();
        return null;
    }
}

// Function to get user orders
function getUserOrders($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching orders: " . $e->getMessage();
        return [];
    }
}

// Function to get seller orders
function getSellerOrders($db, $seller_id) {
    try {
        $stmt = $db->prepare("SELECT o.*, oi.*, p.name as product_name, u.username as buyer_name
                              FROM orders o
                              JOIN order_items oi ON o.id = oi.order_id
                              JOIN products p ON oi.product_id = p.id
                              JOIN users u ON o.user_id = u.id
                              WHERE p.seller_id = ?
                              ORDER BY o.created_at DESC");
        $stmt->execute([$seller_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "Error fetching seller orders: " . $e->getMessage();
        return [];
    }
}

// Get products based on filters
$category = isset($_GET['category']) ? $_GET['category'] : null;
$search = isset($_GET['search']) ? $_GET['search'] : null;
$products = getProducts($db, $category, $search);

// Get categories
$categories = getCategories($db);

// Get cart items
$cart = getCartItems($db);

// Get shop settings if user is logged in
$shop = null;
if (isset($_SESSION['user_id'])) {
    $shop = getShopSettings($db, $_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handmade Haven - Etsy Clone</title>
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f5f1;
            color: #222;
            line-height: 1.6;
        }
        
        a {
            text-decoration: none;
            color: #222;
            transition: color 0.3s ease;
        }
        
        a:hover {
            color: #f1641e;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header Styles */
        header {
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }
        
        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #f1641e;
        }
        
        .search-bar {
            flex-grow: 1;
            margin: 0 20px;
            position: relative;
        }
        
        .search-bar input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid #e1e3df;
            border-radius: 96px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: #f1641e;
        }
        
        .search-bar button {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #222;
        }
        
        .user-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-actions a {
            padding: 8px 15px;
            border-radius: 4px;
            font-weight: 500;
        }
        
        .user-actions .login {
            border: 2px solid #f1641e;
            color: #f1641e;
        }
        
        .user-actions .register {
            background-color: #f1641e;
            color: white;
        }
        
        .user-actions .cart {
            position: relative;
            font-size: 20px;
        }
        
        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: #f1641e;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }
        
        .categories {
            display: flex;
            justify-content: center;
            padding: 10px 0;
            border-top: 1px solid #e1e3df;
            overflow-x: auto;
        }
        
        .categories a {
            margin: 0 15px;
            white-space: nowrap;
            font-weight: 500;
            padding: 5px 0;
            position: relative;
        }
        
        .categories a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: #f1641e;
            transition: width 0.3s ease;
        }
        
        .categories a:hover::after {
            width: 100%;
        }
        
        /* Main Content Styles */
        main {
            padding: 30px 0;
        }
        
        .section-title {
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e1e3df;
        }
        
        /* Product Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
        }
        
        .product-card {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .product-image {
            height: 200px;
            width: 100%;
            object-fit: cover;
        }
        
        .product-info {
            padding: 15px;
        }
        
        .product-title {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-price {
            font-size: 18px;
            font-weight: bold;
            color: #222;
            margin-bottom: 10px;
        }
        
        .product-seller {
            font-size: 14px;
            color: #757575;
            margin-bottom: 15px;
        }
        
        .add-to-cart {
            display: block;
            width: 100%;
            padding: 10px;
            background-color: #f1641e;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
            text-align: center;
        }
        
        .add-to-cart:hover {
            background-color: #e74d10;
        }
        
        /* Forms */
        .form-container {
            max-width: 500px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .form-title {
            font-size: 24px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e3df;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #f1641e;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-submit {
            display: block;
            width: 100%;
            padding: 12px;
            background-color: #f1641e;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        
        .form-submit:hover {
            background-color: #e74d10;
        }
        
        .form-footer {
            margin-top: 20px;
            text-align: center;
        }
        
        /* Cart Styles */
        .cart-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .cart-item {
            display: flex;
            border-bottom: 1px solid #e1e3df;
            padding: 15px 0;
        }
        
        .cart-item-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 4px;
        }
        
        .cart-item-details {
            flex-grow: 1;
            padding: 0 15px;
        }
        
        .cart-item-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .cart-item-price {
            font-size: 16px;
            color: #757575;
            margin-bottom: 10px;
        }
        
        .cart-item-quantity {
            display: flex;
            align-items: center;
        }
        
        .quantity-btn {
            width: 30px;
            height: 30px;
            background-color: #f5f5f1;
            border: 1px solid #e1e3df;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .quantity-input {
            width: 40px;
            height: 30px;
            text-align: center;
            margin: 0 10px;
            border: 1px solid #e1e3df;
            border-radius: 4px;
        }
        
        .cart-item-subtotal {
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100px;
        }
        
        .cart-summary {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e1e3df;
        }
        
        .cart-total {
            display: flex;
            justify-content: space-between;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .checkout-btn {
            display: block;
            width: 100%;
            padding: 15px;
            background-color: #f1641e;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 18px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
            text-align: center;
        }
        
        .checkout-btn:hover {
            background-color: #e74d10;
        }
        
        /* Product Detail Styles */
        .product-detail {
            display: flex;
            gap: 30px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        
        .product-detail-image {
            flex: 1;
            max-width: 500px;
        }
        
        .product-detail-image img {
            width: 100%;
            border-radius: 8px;
        }
        
        .product-detail-info {
            flex: 1;
        }
        
        .product-detail-title {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .product-detail-price {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 15px;
        }
        
        .product-detail-seller {
            font-size: 16px;
            color: #757575;
            margin-bottom: 20px;
        }
        
        .product-detail-description {
            margin-bottom: 30px;
            line-height: 1.8;
        }
        
        /* Dashboard Styles */
        .dashboard {
            display: flex;
            gap: 30px;
        }
        
        .dashboard-sidebar {
            width: 250px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .dashboard-sidebar ul {
            list-style: none;
        }
        
        .dashboard-sidebar ul li {
            margin-bottom: 10px;
        }
        
        .dashboard-sidebar ul li a {
            display: block;
            padding: 10px;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }
        
        .dashboard-sidebar ul li a:hover,
        .dashboard-sidebar ul li a.active {
            background-color: #f5f5f1;
            color: #f1641e;
        }
        
        .dashboard-content {
            flex-grow: 1;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e1e3df;
        }
        
        table th {
            background-color: #f5f5f1;
            font-weight: 600;
        }
        
        table tr:hover {
            background-color: #f9f9f9;
        }
        
        /* Shop Settings Styles */
        .shop-banner {
            height: 200px;
            width: 100%;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .shop-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .shop-logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 20px;
        }
        
        .shop-info {
            flex-grow: 1;
        }
        
        .shop-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .shop-description {
            color: #757575;
            margin-bottom: 10px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Footer Styles */
        footer {
            background-color: #2f466c;
            color: white;
            padding: 40px 0;
            margin-top: 50px;
        }
        
        .footer-content {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        
        .footer-section {
            flex: 1;
            min-width: 200px;
            margin-bottom: 30px;
        }
        
        .footer-section h3 {
            font-size: 18px;
            margin-bottom: 15px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-section h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 2px;
            background-color: #f1641e;
        }
        
        .footer-section ul {
            list-style: none;
        }
        
        .footer-section ul li {
            margin-bottom: 10px;
        }
        
        .footer-section ul li a {
            color: #e1e3df;
            transition: color 0.3s ease;
        }
        
        .footer-section ul li a:hover {
            color: #f1641e;
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .header-top {
                flex-direction: column;
                align-items: stretch;
            }
            
            .logo {
                text-align: center;
                margin-bottom: 15px;
            }
            
            .search-bar {
                margin: 15px 0;
            }
            
            .user-actions {
                justify-content: center;
                margin-top: 15px;
            }
            
            .categories {
                justify-content: flex-start;
                padding: 10px;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
            
            .product-detail {
                flex-direction: column;
            }
            
            .product-detail-image {
                max-width: 100%;
            }
            
            .dashboard {
                flex-direction: column;
            }
            
            .dashboard-sidebar {
                width: 100%;
                margin-bottom: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .cart-item {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .cart-item-details {
                padding: 15px 0;
            }
            
            .cart-item-quantity {
                justify-content: center;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-top">
                <div class="logo">Handmade Haven</div>
                
                <form class="search-bar" action="index.php" method="GET">
                    <input type="hidden" name="page" value="home">
                    <input type="text" name="search" placeholder="Search for anything" value="<?php echo $search ?? ''; ?>">
                    <button type="submit">🔍</button>
                </form>
                
                <div class="user-actions">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <span>Hello, <?php echo $_SESSION['username']; ?></span>
                        <?php if (isset($_SESSION['is_seller']) && $_SESSION['is_seller']): ?>
                            <a href="#" onclick="changePage('seller_dashboard')">Seller Dashboard</a>
                        <?php else: ?>
                            <a href="#" onclick="changePage('become_seller')">Become a Seller</a>
                        <?php endif; ?>
                        <a href="#" onclick="changePage('add_product')">Sell</a>
                        <a href="#" onclick="changePage('cart')" class="cart">
                            🛒
                            <?php if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                                <span class="cart-count"><?php echo array_sum($_SESSION['cart']); ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="#" onclick="logout()">Logout</a>
                    <?php else: ?>
                        <a href="#" onclick="changePage('login')" class="login">Sign In</a>
                        <a href="#" onclick="changePage('register')" class="register">Register</a>
                        <a href="#" onclick="changePage('cart')" class="cart">
                            🛒
                            <?php if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                                <span class="cart-count"><?php echo array_sum($_SESSION['cart']); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="categories">
                <a href="#" onclick="changePage('home')">All Categories</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="#" onclick="filterByCategory('<?php echo $cat['slug']; ?>')"><?php echo $cat['name']; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </header>
    
    <main class="container">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($page === 'home'): ?>
            <h1 class="section-title">
                <?php 
                if ($search) {
                    echo "Search results for \"$search\"";
                } elseif ($category) {
                    foreach ($categories as $cat) {
                        if ($cat['slug'] === $category) {
                            echo $cat['name'] . " Products";
                            break;
                        }
                    }
                } else {
                    echo "Featured Products";
                }
                ?>
            </h1>
            
            <div class="products-grid">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <img src="<?php echo $product['image'] ? $product['image'] : '/placeholder.svg?height=200&width=250'; ?>" alt="<?php echo $product['name']; ?>" class="product-image">
                            <div class="product-info">
                                <h3 class="product-title"><?php echo $product['name']; ?></h3>
                                <p class="product-price">$<?php echo number_format($product['price'], 2); ?></p>
                                <p class="product-seller">By <?php echo $product['seller_name']; ?></p>
                                <a href="#" onclick="addToCart(<?php echo $product['id']; ?>)" class="add-to-cart">Add to Cart</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 50px 0;">
                        <h2>No products found</h2>
                        <p>Try a different search or category.</p>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($page === 'login'): ?>
            <div class="form-container">
                <h2 class="form-title">Sign In</h2>
                <form action="index.php?page=login" method="POST">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="form-submit">Sign In</button>
                    <div class="form-footer">
                        <p>Don't have an account? <a href="#" onclick="changePage('register')">Register</a></p>
                    </div>
                </form>
            </div>
            
        <?php elseif ($page === 'register'): ?>
            <div class="form-container">
                <h2 class="form-title">Create an Account</h2>
                <form action="index.php?page=register" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <div class="form-group">
                        <input type="checkbox" id="is_seller" name="is_seller">
                        <label for="is_seller" style="display: inline-block; margin-left: 5px;">I want to sell on Handmade Haven</label>
                    </div>
                    <button type="submit" name="register" class="form-submit">Register</button>
                    <div class="form-footer">
                        <p>Already have an account? <a href="#" onclick="changePage('login')">Sign In</a></p>
                    </div>
                </form>
            </div>
            
        <?php elseif ($page === 'add_product'): ?>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="alert alert-error">You must be logged in to add a product.</div>
                <p><a href="#" onclick="changePage('login')">Sign in</a> to continue.</p>
            <?php elseif (!isset($_SESSION['is_seller']) || !$_SESSION['is_seller']): ?>
                <div class="alert alert-error">You must be a seller to add products.</div>
                <p><a href="#" onclick="changePage('become_seller')">Become a seller</a> to continue.</p>
            <?php else: ?>
                <div class="form-container" style="max-width: 700px;">
                    <h2 class="form-title">Add a New Product</h2>
                    <form action="index.php?page=add_product" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="name">Product Name</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="price">Price ($)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="stock">Stock Quantity</label>
                            <input type="number" id="stock" name="stock" min="1" value="1" required>
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" required>
                                <option value="">Select a category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo $cat['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="image">Product Image</label>
                            <input type="file" id="image" name="image" accept="image/*">
                        </div>
                        <button type="submit" name="add_product" class="form-submit">Add Product</button>
                    </form>
                </div>
            <?php endif; ?>
            
        <?php elseif ($page === 'become_seller'): ?>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="alert alert-error">You must be logged in to become a seller.</div>
                <p><a href="#" onclick="changePage('login')">Sign in</a> to continue.</p>
            <?php else: ?>
                <div class="form-container" style="max-width: 700px;">
                    <h2 class="form-title">Set Up Your Shop</h2>
                    <form action="index.php?page=become_seller" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="shop_name">Shop Name</label>
                            <input type="text" id="shop_name" name="shop_name" value="<?php echo $shop ? $shop['shop_name'] : $_SESSION['username'] . "'s Shop"; ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shop_description">Shop Description</label>
                            <textarea id="shop_description" name="shop_description"><?php echo $shop ? $shop['shop_description'] : ''; ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="shop_banner">Shop Banner</label>
                            <input type="file" id="shop_banner" name="shop_banner" accept="image/*">
                            <?php if ($shop && $shop['shop_banner']): ?>
                                <p>Current banner: <?php echo $shop['shop_banner']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="shop_logo">Shop Logo</label>
                            <input type="file" id="shop_logo" name="shop_logo" accept="image/*">
                            <?php if ($shop && $shop['shop_logo']): ?>
                                <p>Current logo: <?php echo $shop['shop_logo']; ?></p>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="update_shop" class="form-submit">Save Shop Settings</button>
                    </form>
                </div>
            <?php endif; ?>
            
        <?php elseif ($page === 'seller_dashboard'): ?>
            <?php if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_seller']) || !$_SESSION['is_seller']): ?>
                <div class="alert alert-error">You must be a seller to access the dashboard.</div>
                <p><a href="#" onclick="changePage('become_seller')">Become a seller</a> to continue.</p>
            <?php else: ?>
                <div class="dashboard">
                    <div class="dashboard-sidebar">
                        <h3>Seller Dashboard</h3>
                        <ul>
                            <li><a href="#" onclick="changeDashboardTab('products')" class="active">My Products</a></li>
                            <li><a href="#" onclick="changeDashboardTab('orders')">Orders</a></li>
                            <li><a href="#" onclick="changeDashboardTab('shop_settings')">Shop Settings</a></li>
                        </ul>
                    </div>
                    
                    <div class="dashboard-content">
                        <div id="products-tab" class="dashboard-tab">
                            <h2 class="section-title">My Products</h2>
                            <a href="#" onclick="changePage('add_product')" class="add-to-cart" style="display: inline-block; width: auto; margin-bottom: 20px; padding: 10px 20px;">Add New Product</a>
                            
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Image</th>
                                            <th>Name</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Category</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $seller_products = getProducts($db, null, null, $_SESSION['user_id']);
                                        if (count($seller_products) > 0):
                                            foreach ($seller_products as $product):
                                        ?>
                                            <tr>
                                                <td>
                                                    <img src="<?php echo $product['image'] ? $product['image'] : '/placeholder.svg?height=50&width=50'; ?>" alt="<?php echo $product['name']; ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                                </td>
                                                <td><?php echo $product['name']; ?></td>
                                                <td>$<?php echo number_format($product['price'], 2); ?></td>
                                                <td><?php echo $product['stock']; ?></td>
                                                <td><?php echo $product['category_name']; ?></td>
                                                <td>
                                                    <a href="#" onclick="editProduct(<?php echo $product['id']; ?>)">Edit</a> | 
                                                    <a href="#" onclick="deleteProduct(<?php echo $product['id']; ?>)" style="color: #e74d10;">Delete</a>
                                                </td>
                                            </tr>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                            <tr>
                                                <td colspan="6" style="text-align: center;">No products found. <a href="#" onclick="changePage('add_product')">Add your first product</a>.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div id="orders-tab" class="dashboard-tab" style="display: none;">
                            <h2 class="section-title">Orders</h2>
                            
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Price</th>
                                            <th>Buyer</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $seller_orders = getSellerOrders($db, $_SESSION['user_id']);
                                        if (count($seller_orders) > 0):
                                            foreach ($seller_orders as $order):
                                        ?>
                                            <tr>
                                                <td><?php echo $order['order_id']; ?></td>
                                                <td><?php echo $order['product_name']; ?></td>
                                                <td><?php echo $order['quantity']; ?></td>
                                                <td>$<?php echo number_format($order['price'], 2); ?></td>
                                                <td><?php echo $order['buyer_name']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                                <td><?php echo ucfirst($order['status']); ?></td>
                                            </tr>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                            <tr>
                                                <td colspan="7" style="text-align: center;">No orders found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div id="shop_settings-tab" class="dashboard-tab" style="display: none;">
                            <h2 class="section-title">Shop Settings</h2>
                            
                            <?php if ($shop): ?>
                                <?php if ($shop['shop_banner']): ?>
                                    <img src="<?php echo $shop['shop_banner']; ?>" alt="Shop Banner" class="shop-banner">
                                <?php endif; ?>
                                
                                <div class="shop-header">
                                    <?php if ($shop['shop_logo']): ?>
                                        <img src="<?php echo $shop['shop_logo']; ?>" alt="Shop Logo" class="shop-logo">
                                    <?php endif; ?>
                                    
                                    <div class="shop-info">
                                        <h3 class="shop-name"><?php echo $shop['shop_name']; ?></h3>
                                        <p class="shop-description"><?php echo $shop['shop_description']; ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <a href="#" onclick="changePage('become_seller')" class="add-to-cart" style="display: inline-block; width: auto; margin-top: 20px; padding: 10px 20px;">Edit Shop Settings</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php elseif ($page === 'cart'): ?>
            <h1 class="section-title">Your Shopping Cart</h1>
            
            <?php if (empty($cart['items'])): ?>
                <div style="text-align: center; padding: 50px 0;">
                    <h2>Your cart is empty</h2>
                    <p>Find something you love? Add it to your cart!</p>
                    <a href="#" onclick="changePage('home')" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #f1641e; color: white; border-radius: 4px;">Continue Shopping</a>
                </div>
            <?php else: ?>
                <div class="cart-container">
                    <?php foreach ($cart['items'] as $item): ?>
                        <div class="cart-item">
                            <img src="<?php echo $item['image'] ? $item['image'] : '/placeholder.svg?height=100&width=100'; ?>" alt="<?php echo $item['name']; ?>" class="cart-item-image">
                            <div class="cart-item-details">
                                <h3 class="cart-item-title"><?php echo $item['name']; ?></h3>
                                <p class="cart-item-price">$<?php echo number_format($item['price'], 2); ?> each</p>
                                <div class="cart-item-quantity">
                                    <button class="quantity-btn" onclick="updateQuantity(<?php echo $item['id']; ?>, -1)">-</button>
                                    <input type="number" class="quantity-input" value="<?php echo $item['quantity']; ?>" min="1" onchange="setQuantity(<?php echo $item['id']; ?>, this.value)">
                                    <button class="quantity-btn" onclick="updateQuantity(<?php echo $item['id']; ?>, 1)">+</button>
                                </div>
                            </div>
                            <div class="cart-item-subtotal">
                                $<?php echo number_format($item['subtotal'], 2); ?>
                            </div>
                            <a href="#" onclick="removeFromCart(<?php echo $item['id']; ?>)" style="color: #e74d10; margin-left: 15px;">Remove</a>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="cart-summary">
                        <div class="cart-total">
                            <span>Total:</span>
                            <span>$<?php echo number_format($cart['total'], 2); ?></span>
                        </div>
                        
                        <a href="#" onclick="changePage('checkout')" class="checkout-btn">Proceed to Checkout</a>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php elseif ($page === 'checkout'): ?>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="alert alert-error">You must be logged in to checkout.</div>
                <p><a href="#" onclick="changePage('login')">Sign in</a> to continue.</p>
            <?php elseif (empty($cart['items'])): ?>
                <div class="alert alert-error">Your cart is empty.</div>
                <p><a href="#" onclick="changePage('home')">Browse products</a> to add items to your cart.</p>
            <?php else: ?>
                <div class="form-container">
                    <h2 class="form-title">Checkout</h2>
                    <form action="index.php?page=checkout" method="POST">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="address">Shipping Address</label>
                            <textarea id="address" name="address" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" required>
                        </div>
                        <div class="form-group">
                            <label for="zip">ZIP Code</label>
                            <input type="text" id="zip" name="zip" required>
                        </div>
                        <div class="form-group">
                            <label for="card">Credit Card Number</label>
                            <input type="text" id="card" name="card" placeholder="1234 5678 9012 3456" required>
                        </div>
                        <div class="form-group">
                            <label for="expiry">Expiry Date</label>
                            <input type="text" id="expiry" name="expiry" placeholder="MM/YY" required>
                        </div>
                        <div class="form-group">
                            <label for="cvv">CVV</label>
                            <input type="text" id="cvv" name="cvv" placeholder="123" required>
                        </div>
                        <input type="hidden" name="total_amount" value="<?php echo $cart['total']; ?>">
                        <div class="cart-total" style="margin-bottom: 20px;">
                            <span>Total Amount:</span>
                            <span>$<?php echo number_format($cart['total'], 2); ?></span>
                        </div>
                        <button type="submit" name="checkout" class="form-submit">Place Order</button>
                    </form>
                </div>
            <?php endif; ?>
            
        <?php elseif ($page === 'order_confirmation'): ?>
            <div style="text-align: center; padding: 50px 0;">
                <h2>Thank You for Your Order!</h2>
                <p>Your order has been placed successfully.</p>
                <p>Order ID: <strong><?php echo $_GET['order_id'] ?? 'N/A'; ?></strong></p>
                <p>We'll send you an email with your order details shortly.</p>
                <a href="#" onclick="changePage('home')" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #f1641e; color: white; border-radius: 4px;">Continue Shopping</a>
            </div>
        <?php endif; ?>
    </main>
    
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Shop</h3>
                    <ul>
                        <?php foreach (array_slice($categories, 0, 5) as $cat): ?>
                            <li><a href="#" onclick="filterByCategory('<?php echo $cat['slug']; ?>')"><?php echo $cat['name']; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>About</h3>
                    <ul>
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Policies</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Press</a></li>
                        <li><a href="#">Impact</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Help</h3>
                    <ul>
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">Privacy Settings</a></li>
                        <li><a href="#">Contact Us</a></li>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Terms of Use</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Stay Connected</h3>
                    <ul>
                        <li><a href="#">Facebook</a></li>
                        <li><a href="#">Instagram</a></li>
                        <li><a href="#">Pinterest</a></li>
                        <li><a href="#">Twitter</a></li>
                        <li><a href="#">YouTube</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2023 Handmade Haven. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <script>
        // Function to change page using JavaScript
        function changePage(page) {
            window.location.href = 'index.php?page=' + page;
        }
        
        // Function to filter products by category
        function filterByCategory(category) {
            window.location.href = 'index.php?page=home&category=' + category;
        }
        
        // Function to add product to cart
        function addToCart(productId) {
            window.location.href = 'index.php?page=home&add_to_cart=1&product_id=' + productId;
        }
        
        // Function to update cart item quantity
        function updateQuantity(productId, change) {
            // Get current quantity
            const input = document.querySelector(`input[onchange="setQuantity(${productId}, this.value)"]`);
            let quantity = parseInt(input.value) + change;
            
            // Ensure quantity is at least 1
            if (quantity < 1) quantity = 1;
            
            // Update input value
            input.value = quantity;
            
            // Update cart
            setQuantity(productId, quantity);
        }
        
        // Function to set cart item quantity
        function setQuantity(productId, quantity) {
            window.location.href = 'index.php?page=cart&update_cart=1&product_id=' + productId + '&quantity=' + quantity;
        }
        
        // Function to remove item from cart
        function removeFromCart(productId) {
            if (confirm('Are you sure you want to remove this item from your cart?')) {
                window.location.href = 'index.php?page=cart&remove_from_cart=1&product_id=' + productId;
            }
        }
        
        // Function to change dashboard tab
        function changeDashboardTab(tab) {
            // Hide all tabs
            document.querySelectorAll('.dashboard-tab').forEach(function(el) {
                el.style.display = 'none';
            });
            
            // Show selected tab
            document.getElementById(tab + '-tab').style.display = 'block';
            
            // Update active class
            document.querySelectorAll('.dashboard-sidebar a').forEach(function(el) {
                el.classList.remove('active');
            });
            
            document.querySelector(`.dashboard-sidebar a[onclick="changeDashboardTab('${tab}')"]`).classList.add('active');
        }
        
        // Function to edit product
        function editProduct(productId) {
            window.location.href = 'index.php?page=edit_product&id=' + productId;
        }
        
        // Function to delete product
        function deleteProduct(productId) {
            if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
                window.location.href = 'index.php?page=delete_product&id=' + productId;
            }
        }
        
        // Function to logout
        function logout() {
            window.location.href = 'index.php?logout=1';
        }
    </script>
</body>
</html>
