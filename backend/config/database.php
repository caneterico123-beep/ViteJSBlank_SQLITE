<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $configuredPath = getenv('AUTH_DATABASE_PATH');
    if ($configuredPath === false || $configuredPath === '') {
        $projectKey = substr(hash('sha256', dirname(__DIR__, 2)), 0, 12);
        $databaseDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'northstar-auth-' . $projectKey;
        $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'auth.sqlite';
    } else {
        $databasePath = $configuredPath;
        $databaseDirectory = dirname($databasePath);
    }
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0700, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Database directory could not be created.');
    }

    $connection = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'cashier', 'admin')),
            last_login_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
    );
    $columns = $connection->query('PRAGMA table_info(users)')->fetchAll();
    $columnNames = array_column($columns, 'name');
    if (!in_array('role', $columnNames, true)) {
        $connection->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    }
    if (!in_array('last_login_at', $columnNames, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME');
    }
    $userTableSql = $connection->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (is_string($userTableSql) && !str_contains($userTableSql, "'cashier'")) {
        $connection->exec(
            "CREATE TABLE users_replacement (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL COLLATE NOCASE UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'cashier', 'admin')),
                last_login_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",
        );
        $connection->exec(
            'INSERT INTO users_replacement (id, name, email, password, role, last_login_at, created_at, updated_at)
             SELECT id, name, email, password, role, last_login_at, created_at, updated_at FROM users',
        );
        $connection->exec('PRAGMA foreign_keys = OFF');
        try {
            $connection->beginTransaction();
            $connection->exec('DROP TABLE users');
            $connection->exec('ALTER TABLE users_replacement RENAME TO users');
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $connection->exec('PRAGMA foreign_keys = ON');
            throw $error;
        }
        $connection->exec('PRAGMA foreign_keys = ON');
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS user_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            theme TEXT NOT NULL DEFAULT 'system' CHECK (theme IN ('light', 'dark', 'system')),
            email_notifications INTEGER NOT NULL DEFAULT 1 CHECK (email_notifications IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key TEXT PRIMARY KEY,
            window_started INTEGER NOT NULL,
            attempts INTEGER NOT NULL
        )',
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_users_last_login_at ON users(last_login_at)');
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS products (
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
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_number TEXT NOT NULL UNIQUE,
            idempotency_key TEXT NOT NULL UNIQUE,
            idempotency_hash TEXT NOT NULL,
            cashier_id INTEGER NOT NULL,
            customer_id INTEGER,
            customer_name TEXT,
            subtotal_cents INTEGER NOT NULL CHECK (subtotal_cents >= 0),
            discount_cents INTEGER NOT NULL DEFAULT 0 CHECK (discount_cents >= 0),
            tax_cents INTEGER NOT NULL DEFAULT 0 CHECK (tax_cents >= 0),
            total_cents INTEGER NOT NULL CHECK (total_cents >= 0),
            payment_method TEXT NOT NULL CHECK (payment_method IN ('cash', 'card', 'ewallet')),
            amount_received_cents INTEGER,
            change_cents INTEGER NOT NULL DEFAULT 0 CHECK (change_cents >= 0),
            payment_status TEXT NOT NULL CHECK (payment_status IN ('paid', 'pending', 'voided', 'cancelled')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            unit_price_cents INTEGER NOT NULL CHECK (unit_price_cents >= 0),
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            line_total_cents INTEGER NOT NULL CHECK (line_total_cents >= 0),
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        )',
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL CHECK (movement_type IN ('sale', 'adjustment', 'void', 'return')),
            quantity_change INTEGER NOT NULL CHECK (quantity_change != 0),
            reference_sale_id INTEGER,
            notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (reference_sale_id) REFERENCES sales(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS sale_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL CHECK (action IN ('created', 'voided', 'cancelled')),
            reason TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_products_category_active ON products(category_id, is_active)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_products_name ON products(name COLLATE NOCASE)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sales_cashier_created ON sales(cashier_id, created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_inventory_product_created ON inventory_movements(product_id, created_at)');

    return $connection;
}