#!/usr/bin/env python3
"""Generate an Argon2id password hash compatible with PHP password_verify().

Install dependency once:
    python -m pip install argon2-cffi

Run:
    python make_password_hash.py

The script prompts for the password without echoing it and prints both the hash
and a ready-to-run SQL UPDATE statement.
"""
from __future__ import annotations

import getpass
import sys

try:
    from argon2 import PasswordHasher, Type
except ImportError:
    print("Не найден пакет argon2-cffi.", file=sys.stderr)
    print("Установите: python -m pip install argon2-cffi", file=sys.stderr)
    raise SystemExit(2)


def main() -> int:
    password = getpass.getpass("Новый пароль: ")
    repeat = getpass.getpass("Повторите пароль: ")
    if password != repeat:
        print("Пароли не совпадают.", file=sys.stderr)
        return 1
    if not password:
        print("Пустой пароль не допускается.", file=sys.stderr)
        return 1

    # Same parameters as Solanace/PHP: 64 MiB, 4 iterations, 2 lanes.
    ph = PasswordHasher(
        time_cost=4,
        memory_cost=65536,
        parallelism=2,
        hash_len=32,
        salt_len=16,
        type=Type.ID,
    )
    hash_value = ph.hash(password)

    print("\nArgon2id hash:\n")
    print(hash_value)
    print("\nSQL для ручной записи в базу:\n")
    escaped = hash_value.replace("'", "''")
    print(
        "UPDATE app_auth "
        f"SET password_hash = '{escaped}', password_md5 = NULL "
        "WHERE id = 1;"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
