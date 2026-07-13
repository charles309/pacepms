#!/usr/bin/env python3
"""
Quick connectivity test for an Amazon RDS (MySQL) database.

Credentials are NEVER hardcoded here. Set them as environment variables
(or put them in a local .env file that is already gitignored) before running:

    export DB_HOST=your-instance.xxxxxxxxxx.us-east-1.rds.amazonaws.com
    export DB_PORT=3306
    export DB_USER=your_user
    export DB_PASS=your_password
    export DB_NAME=your_database   # optional

    python3 test_amazon_db.py

If your RDS instance enforces SSL, point DB_SSL_CA at the Amazon RDS CA
bundle (download from AWS docs) and it will be used automatically:

    export DB_SSL_CA=/path/to/rds-combined-ca-bundle.pem
"""

import os
import sys
import time

import mysql.connector
from mysql.connector import pooling, Error as MySQLError


def get_config():
    host = os.environ.get("DB_HOST")
    user = os.environ.get("DB_USER")
    password = os.environ.get("DB_PASS")

    missing = [name for name, val in (("DB_HOST", host), ("DB_USER", user), ("DB_PASS", password)) if not val]
    if missing:
        print(f"Missing required environment variables: {', '.join(missing)}")
        print("Set them and re-run, e.g.:\n  export DB_HOST=... DB_USER=... DB_PASS=...")
        sys.exit(1)

    config = {
        "host": host,
        "port": int(os.environ.get("DB_PORT", "3306")),
        "user": user,
        "password": password,
        "connection_timeout": int(os.environ.get("DB_CONNECT_TIMEOUT", "10")),
    }

    db_name = os.environ.get("DB_NAME")
    if db_name:
        config["database"] = db_name

    ssl_ca = os.environ.get("DB_SSL_CA")
    if ssl_ca:
        config["ssl_ca"] = ssl_ca
        config["ssl_verify_cert"] = True

    return config


def main():
    config = get_config()
    safe_config = {k: v for k, v in config.items() if k != "password"}
    print(f"Attempting connection with: {safe_config}")

    start = time.time()
    try:
        # Use a small pool of 1 just to exercise pooling.get_connection() too.
        pool = pooling.MySQLConnectionPool(pool_name="amazon_db_test", pool_size=1, **config)
        conn = pool.get_connection()
    except MySQLError as e:
        print(f"FAILED to connect ({time.time() - start:.2f}s): {e}")
        sys.exit(1)

    try:
        cursor = conn.cursor()
        cursor.execute("SELECT VERSION(), DATABASE(), NOW()")
        version, current_db, now = cursor.fetchone()
        print(f"Connected in {time.time() - start:.2f}s")
        print(f"  Server version : {version}")
        print(f"  Current DB     : {current_db}")
        print(f"  Server time    : {now}")

        cursor.execute("SHOW DATABASES")
        dbs = [row[0] for row in cursor.fetchall()]
        print(f"  Visible DBs    : {', '.join(dbs)}")

        cursor.close()
        print("\nDatabase connection test: SUCCESS")
    except MySQLError as e:
        print(f"Connected, but a query failed: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
