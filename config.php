<?php
// HEISVERSE settings. Edit this file, save, refresh the page.

// Free key from https://www.themoviedb.org/settings/api (v3 key or v4 read token).
const TMDB_API_KEY = '5bc2f1bff7fc9b7651fd5dca0fde4e82';

// Country used for "where to watch" results (2-letter code, e.g. RW, US, GB, KE).
const DEFAULT_REGION = 'RW';

const TMDB_LANGUAGE = 'en-US';

// Set to false ONLY if XAMPP shows an SSL certificate error (see README, Troubleshooting).
const TMDB_VERIFY_SSL = true;

// MySQL connection. These are the XAMPP defaults (user root, empty password).
// The database is created by importing database.sql in phpMyAdmin.
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'heisverse';
const DB_USER = 'root';
const DB_PASS = '';
