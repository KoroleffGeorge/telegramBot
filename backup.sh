#!/bin/bash

if [ $# -ne 2 ]; then
  echo "Usage: $0 database-password directory"
  exit 1
fi

DB_USER="bot"
DB_NAME="telegram_bot"
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).dump"

# Создание каталога для сохранения копии, если он не существует
mkdir -p "$2"

# Получение текущей даты в формате ГГГГ-ММ-ДД
date=$(date +"%Y-%m-%d")

# Выполнение mysqldump и сохранение копии в файл
PGPASSWORD="$1" pg_dump -U "$DB_USER" -d "$DB_NAME" -f "$2/$BACKUP_FILE"

# Сообщение об успешном создании копии
echo "Копия базы данных успешно создана в файле " "$2/$BACKUP_FILE"