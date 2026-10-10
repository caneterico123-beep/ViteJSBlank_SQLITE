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
            username TEXT COLLATE NOCASE,
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
    if (!in_array('username', $columnNames, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN username TEXT COLLATE NOCASE');
    }
    $userTableSql = $connection->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (is_string($userTableSql) && !str_contains($userTableSql, "'cashier'")) {
        $connection->exec(
            "CREATE TABLE users_replacement (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL COLLATE NOCASE UNIQUE,
                username TEXT COLLATE NOCASE,
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'cashier', 'admin')),
                last_login_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",
        );
        $connection->exec(
            'INSERT INTO users_replacement (id, name, email, username, password, role, last_login_at, created_at, updated_at)
             SELECT id, name, email, username, password, role, last_login_at, created_at, updated_at FROM users',
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
    $tenantSchemaExists = (int) $connection->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'businesses'",
    )->fetchColumn() > 0;
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS businesses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            industry TEXT NOT NULL CHECK (industry IN ('retail', 'grocery', 'pharmacy', 'clinic', 'bakery', 'hotel', 'restaurant', 'rent_a_car', 'computer_store', 'coffee_shop')),
            business_type TEXT,
            logo_url TEXT,
            email TEXT,
            phone TEXT,
            address TEXT,
            currency TEXT NOT NULL DEFAULT 'PHP',
            timezone TEXT NOT NULL DEFAULT 'Asia/Manila',
            settings TEXT NOT NULL DEFAULT '{}',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS business_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('owner', 'admin', 'manager', 'cashier', 'inventory_staff', 'receptionist', 'pharmacist', 'clinician', 'kitchen_staff', 'mechanic', 'housekeeper')),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (business_id, user_id),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_business_members_user ON business_members(user_id, is_active)');
    if (!$tenantSchemaExists) {
        $connection->exec(
            "INSERT INTO businesses (id, name, industry, currency, timezone)
             SELECT 1, 'Northstar Workspace', 'retail', 'PHP', 'Asia/Manila'
             WHERE EXISTS (SELECT 1 FROM users)
               AND NOT EXISTS (SELECT 1 FROM businesses)",
        );
        $connection->exec(
            "INSERT OR IGNORE INTO business_members (business_id, user_id, role)
             SELECT 1, id, CASE role WHEN 'admin' THEN 'owner' WHEN 'cashier' THEN 'cashier' ELSE 'inventory_staff' END
             FROM users WHERE EXISTS (SELECT 1 FROM businesses WHERE id = 1)",
        );
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
    $connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username COLLATE NOCASE) WHERE username IS NOT NULL');
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    $categoryColumns = array_column($connection->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
    if (!in_array('description', $categoryColumns, true)) {
        $connection->exec('ALTER TABLE categories ADD COLUMN description TEXT');
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE,
            contact_person TEXT,
            email TEXT,
            phone TEXT,
            address TEXT,
            notes TEXT,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_suppliers_name ON suppliers(name COLLATE NOCASE)');
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
    $productColumns = array_column($connection->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    if (!in_array('cost_price_cents', $productColumns, true)) {
        $connection->exec('ALTER TABLE products ADD COLUMN cost_price_cents INTEGER NOT NULL DEFAULT 0 CHECK (cost_price_cents >= 0)');
    }
    if (!in_array('reorder_level', $productColumns, true)) {
        $connection->exec('ALTER TABLE products ADD COLUMN reorder_level INTEGER NOT NULL DEFAULT 5 CHECK (reorder_level >= 0)');
    }
    if (!in_array('supplier_id', $productColumns, true)) {
        $connection->exec('ALTER TABLE products ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL');
    }
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
        "CREATE TABLE IF NOT EXISTS inventory_movement_details (
            movement_id INTEGER PRIMARY KEY,
            transaction_type TEXT NOT NULL CHECK (transaction_type IN ('stock_in', 'stock_out', 'adjustment_increase', 'adjustment_decrease', 'opening_balance')),
            quantity_before INTEGER NOT NULL CHECK (quantity_before >= 0),
            quantity_after INTEGER NOT NULL CHECK (quantity_after >= 0),
            reason TEXT,
            reference_number TEXT,
            supplier_id INTEGER,
            FOREIGN KEY (movement_id) REFERENCES inventory_movements(id) ON DELETE RESTRICT,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            details TEXT NOT NULL DEFAULT '{}',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS validate_inventory_detail_insert
         BEFORE INSERT ON inventory_movement_details
         WHEN NEW.quantity_after <> NEW.quantity_before + (
             SELECT quantity_change FROM inventory_movements WHERE id = NEW.movement_id
         )
         BEGIN
             SELECT RAISE(ABORT, 'Inventory quantity snapshots do not match the movement.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS inventory_details_append_only_update
         BEFORE UPDATE ON inventory_movement_details
         BEGIN
             SELECT RAISE(ABORT, 'Inventory history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS inventory_details_append_only_delete
         BEFORE DELETE ON inventory_movement_details
         BEGIN
             SELECT RAISE(ABORT, 'Inventory history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS inventory_audit_append_only_update
         BEFORE UPDATE ON inventory_audit_logs
         BEGIN
             SELECT RAISE(ABORT, 'Audit history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS inventory_audit_append_only_delete
         BEFORE DELETE ON inventory_audit_logs
         BEGIN
             SELECT RAISE(ABORT, 'Audit history is append-only.');
         END",
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
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS pharmacy_product_details (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            generic_name TEXT,
            brand_name TEXT,
            manufacturer TEXT,
            active_ingredients TEXT NOT NULL DEFAULT '',
            strength TEXT,
            dosage_form TEXT,
            route TEXT,
            package_size TEXT,
            unit_of_measure TEXT NOT NULL DEFAULT 'unit',
            prescription_required INTEGER NOT NULL DEFAULT 0 CHECK (prescription_required IN (0, 1)),
            controlled_classification TEXT NOT NULL DEFAULT 'none',
            storage_instructions TEXT,
            cold_chain INTEGER NOT NULL DEFAULT 0 CHECK (cold_chain IN (0, 1)),
            batch_tracking INTEGER NOT NULL DEFAULT 1 CHECK (batch_tracking IN (0, 1)),
            warnings TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            UNIQUE (business_id, product_id),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS medicine_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL COLLATE NOCASE,
            supplier_id INTEGER,
            manufacturer TEXT,
            manufactured_on TEXT,
            expires_on TEXT NOT NULL,
            quantity_received INTEGER NOT NULL CHECK (quantity_received > 0),
            available_quantity INTEGER NOT NULL CHECK (available_quantity >= 0),
            purchase_cost_cents INTEGER NOT NULL CHECK (purchase_cost_cents >= 0),
            status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'quarantined', 'recalled', 'damaged', 'disposed')),
            receipt_reference TEXT,
            created_by INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            UNIQUE (business_id, product_id, batch_number),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS medicine_batch_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            receipt_key TEXT NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            purchase_cost_cents INTEGER NOT NULL CHECK (purchase_cost_cents >= 0),
            reference_number TEXT,
            created_by INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (business_id, receipt_key),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE RESTRICT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS medicine_batch_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL CHECK (movement_type IN ('receipt', 'sale', 'void', 'adjustment', 'quarantine', 'recall', 'disposal')),
            quantity_change INTEGER NOT NULL,
            quantity_before INTEGER NOT NULL CHECK (quantity_before >= 0),
            quantity_after INTEGER NOT NULL CHECK (quantity_after >= 0),
            reference_type TEXT,
            reference_id INTEGER,
            reason TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK (quantity_after = quantity_before + quantity_change),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS medicine_batch_allocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            sale_id INTEGER NOT NULL,
            sale_item_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (business_id, sale_item_id, batch_id),
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
            FOREIGN KEY (sale_item_id) REFERENCES sale_items(id) ON DELETE RESTRICT,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE RESTRICT
        )",
    );
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_medicine_batches_fefo ON medicine_batches(business_id, product_id, status, expires_on)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_medicine_batches_expiry ON medicine_batches(business_id, expires_on)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_medicine_batch_movements_batch ON medicine_batch_movements(business_id, batch_id, created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_medicine_batch_allocations_sale ON medicine_batch_allocations(business_id, sale_id)');
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_movements_append_only_update
         BEFORE UPDATE ON medicine_batch_movements
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch movement history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_movements_append_only_delete
         BEFORE DELETE ON medicine_batch_movements
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch movement history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_receipts_append_only_update
         BEFORE UPDATE ON medicine_batch_receipts
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch receipt history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_receipts_append_only_delete
         BEFORE DELETE ON medicine_batch_receipts
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch receipt history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_allocations_append_only_update
         BEFORE UPDATE ON medicine_batch_allocations
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch allocation history is append-only.');
         END",
    );
    $connection->exec(
        "CREATE TRIGGER IF NOT EXISTS medicine_batch_allocations_append_only_delete
         BEFORE DELETE ON medicine_batch_allocations
         BEGIN
             SELECT RAISE(ABORT, 'Medicine batch allocation history is append-only.');
         END",
    );
    foreach (['categories', 'suppliers', 'products', 'sales', 'sale_items', 'inventory_movements', 'inventory_movement_details', 'inventory_audit_logs', 'sale_audit_log'] as $table) {
        $tableColumns = array_column($connection->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        if (!in_array('business_id', $tableColumns, true)) {
            $connection->exec('ALTER TABLE ' . $table . ' ADD COLUMN business_id INTEGER REFERENCES businesses(id)');
        }
        if (!$tenantSchemaExists) {
            $connection->exec('UPDATE ' . $table . ' SET business_id = 1 WHERE business_id IS NULL AND EXISTS (SELECT 1 FROM businesses WHERE id = 1)');
        }
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_business ON ' . $table . '(business_id)');
    }
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_products_category_active ON products(category_id, is_active)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_products_supplier_active ON products(supplier_id, is_active)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_products_name ON products(name COLLATE NOCASE)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_inventory_created ON inventory_movements(created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_inventory_details_type ON inventory_movement_details(transaction_type)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sales_cashier_created ON sales(cashier_id, created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sales_business_created ON sales(business_id, created_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id)');
    $connection->exec('CREATE INDEX IF NOT EXISTS idx_inventory_product_created ON inventory_movements(product_id, created_at)');

    return $connection;
}