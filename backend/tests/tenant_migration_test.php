<?php
declare(strict_types=1);

$databasePath = 'file:bizflow-tenant-migration-' . bin2hex(random_bytes(6)) . '?mode=memory&cache=shared';
putenv('AUTH_DATABASE_PATH=' . $databasePath);

function migration_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

try {
    $legacy = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->exec(
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'cashier', 'admin')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    $legacy->exec("INSERT INTO users (name, email, password, role) VALUES ('Legacy Admin', 'legacy@example.test', 'hash', 'admin')");
    $legacy->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL COLLATE NOCASE UNIQUE, is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $legacy->exec("INSERT INTO categories (name) VALUES ('Legacy category')");
    $legacy->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL COLLATE NOCASE, is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $legacy->exec(
        "CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            sku TEXT COLLATE NOCASE UNIQUE,
            barcode TEXT COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            description TEXT,
            price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
            stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
            image_path TEXT,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        )",
    );
    $legacy->exec("INSERT INTO products (category_id, sku, name, price_cents, stock_quantity) VALUES (1, 'LEGACY-001', 'Legacy product', 1200, 8)");
    require __DIR__ . '/../config/database.php';
    $pdo = database_connection();
    $legacy = null;
    $membership = $pdo->query(
        "SELECT businesses.id AS business_id, businesses.industry, business_members.role
         FROM business_members JOIN businesses ON businesses.id = business_members.business_id
         JOIN users ON users.id = business_members.user_id
         WHERE users.email = 'legacy@example.test'",
    );
    $membershipRow = $membership->fetch();
    migration_expect($membershipRow !== false && (int) $membershipRow['business_id'] === 1
        && $membershipRow['industry'] === 'retail' && $membershipRow['role'] === 'owner',
        'create a legacy workspace and preserve the administrator membership');
    $legacyRows = $pdo->query(
        "SELECT products.business_id, products.name, categories.business_id AS category_business_id
         FROM products JOIN categories ON categories.id = products.category_id
         WHERE products.sku = 'LEGACY-001'",
    )->fetch();
    migration_expect($legacyRows !== false && (int) $legacyRows['business_id'] === 1
        && (int) $legacyRows['category_business_id'] === 1 && $legacyRows['name'] === 'Legacy product',
        'preserve and tenant-assign existing product and category data');
} finally {
    if (isset($membership) && $membership instanceof PDOStatement) {
        $membership->closeCursor();
    }
    putenv('AUTH_DATABASE_PATH');
}
