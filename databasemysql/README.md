# QCNet Hardened Universal Business Platform - MySQL / MariaDB Schema

Production-ready MySQL 5.7+, MySQL 8.0+, and MariaDB 10.3+ database architecture for the **QCNet Hardened Realtime Chat Engine** and the **Universal Multi-Tenant Business Dashboard (UBD)**.

---

## 📁 Repository Structure

| File | Description |
| :--- | :--- |
| `00_init_database.sql` | Database initialization (`qcnet_db`, `utf8mb4_unicode_ci`). |
| `01_quickchat_core.sql` | QuickChat core engine: rooms, messages, sessions, encrypted blobs, OAuth states. |
| `02_universal_business_platform.sql` | Multi-tenant organizations, users, RBAC roles, catalog, orders, CRM, theme settings. |
| `03_caching_queues_and_scaling.sql` | Cache layer, asynchronous jobs, failed jobs, cluster nodes, webhooks. |
| `04_security_center_and_audit.sql` | Audit logging, threat detection, IP blocklists, rate limiting, login forensics. |
| `05_seed_and_demo_data.sql` | System default permissions, roles, initial super admin, theme defaults, demo catalog. |
| `qcnet_master_all_in_one.sql` | Unified single-file migration installer containing all modules. |
| `import_database.sh` | Automated shell script to import all migrations into local or remote MariaDB/MySQL. |
| `verify_database.php` | CLI database health verification tool checking table inventory, seeds, and auth. |

---

## 🚀 Quick Start / Database Provisioning

### Method 1: Automated Shell Importer
```bash
bash import_database.sh
```
*Optional environment variables:*
- `DB_USER` (default: `root`)
- `DB_PASS` (default: empty)
- `DB_HOST` (default: `127.0.0.1`)
- `DB_PORT` (default: `3306`)

### Method 2: Single-File Master SQL Import
```bash
mysql -u root -p qcnet_db < qcnet_master_all_in_one.sql
# or MariaDB
mariadb -u root -p qcnet_db < qcnet_master_all_in_one.sql
```

### Method 3: Sequential Migration Run
```bash
mysql -u root -p < 00_init_database.sql
mysql -u root -p qcnet_db < 01_quickchat_core.sql
mysql -u root -p qcnet_db < 02_universal_business_platform.sql
mysql -u root -p qcnet_db < 03_caching_queues_and_scaling.sql
mysql -u root -p qcnet_db < 04_security_center_and_audit.sql
mysql -u root -p qcnet_db < 05_seed_and_demo_data.sql
```

---

## 🔍 Database Health Verification

Run the automated test runner to verify all 39 tables, indexes, constraints, and seed records:
```bash
php verify_database.php
```

Expected output:
```
============================================================
🔍 QCNet Database Verification & Health Check Suite
============================================================
✅ Successfully connected to database 'qcnet_db'.
📊 Checking Table Inventory (39 detected):
  ✓ Table 'rooms' ... EXISTS
  ...
  ✓ Table 'api_tokens' ... EXISTS

📊 Verifying Seed Data & Core Records:
  ✓ Default tenant 'main' (QCNet Enterprise Global) ... FOUND
  ✓ Super admin user 'xbot' (Password Verified) ... AUTHENTICATED
  ✓ System Permissions loaded: 15 ... OK
  ✓ System Roles configured: 5 ... OK
  ✓ Default Chat Rooms available: 3 ... OK
  ✓ Demo Products available: 2 ... OK
  ✓ Theme Settings for 'main' ... LOADED
🎉 All Database Checks Passed! 100% Production Ready.
```

---

## 🛡️ Default Super Administrator Credentials

| Parameter | Value |
| :--- | :--- |
| **Username** | `xbot` |
| **Default Password** | `#123xbot` |
| **Role** | `super_admin` |
| **Tenant** | `main` |
| **Hashing Algorithm** | Argon2id / Bcrypt (`PASSWORD_DEFAULT`) |

*Note: Please change the default password immediately in production.*

---

## 🔒 Security Hardening

- **Prepared Statements**: Strict PDO emulation disabled (`PDO::ATTR_EMULATE_PREPARES => false`).
- **Charset & Collation**: Native `utf8mb4` with `utf8mb4_unicode_ci` preventing multi-byte SQL truncation exploits.
- **Foreign Key Cascades**: Explicit referential integrity with cascading deletes on child relations.
- **Audit Trails**: Non-repudiation audit logging for administrative mutations.
