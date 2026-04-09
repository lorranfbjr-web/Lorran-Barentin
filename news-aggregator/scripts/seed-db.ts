import Database from 'better-sqlite3';
import path from 'path';
import fs from 'fs';
import { fontesSeed } from '../src/data/fontes-seed';

const DB_DIR = path.join(__dirname, '..', 'data');
const DB_PATH = path.join(DB_DIR, 'news.db');

// Ensure data directory exists
if (!fs.existsSync(DB_DIR)) {
  fs.mkdirSync(DB_DIR, { recursive: true });
}

const db = new Database(DB_PATH);
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

// Create tables
db.exec(`
  CREATE TABLE IF NOT EXISTS fontes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    url_base TEXT NOT NULL,
    url_feed TEXT,
    tipo_coleta TEXT NOT NULL DEFAULT 'rss' CHECK(tipo_coleta IN ('rss', 'scraping', 'sitemap')),
    regiao TEXT NOT NULL,
    seletor_titulo TEXT,
    seletor_imagem TEXT,
    seletor_link TEXT,
    seletor_container TEXT,
    prioridade TEXT NOT NULL DEFAULT 'normal' CHECK(prioridade IN ('alta', 'normal')),
    ativo INTEGER NOT NULL DEFAULT 1,
    falhas_consecutivas INTEGER NOT NULL DEFAULT 0,
    ultima_coleta TEXT
  );

  CREATE TABLE IF NOT EXISTS noticias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fonte_id INTEGER NOT NULL,
    titulo TEXT NOT NULL,
    url TEXT NOT NULL UNIQUE,
    imagem_url TEXT,
    data_publicacao TEXT,
    data_coleta TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (fonte_id) REFERENCES fontes(id)
  );

  CREATE INDEX IF NOT EXISTS idx_noticias_data ON noticias(data_publicacao DESC);
  CREATE INDEX IF NOT EXISTS idx_noticias_fonte ON noticias(fonte_id);
  CREATE INDEX IF NOT EXISTS idx_noticias_url ON noticias(url);

  CREATE TABLE IF NOT EXISTS coletas_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fonte_id INTEGER NOT NULL,
    timestamp TEXT NOT NULL DEFAULT (datetime('now')),
    status TEXT NOT NULL CHECK(status IN ('sucesso', 'erro', 'timeout')),
    qtd_novas INTEGER NOT NULL DEFAULT 0,
    mensagem_erro TEXT,
    FOREIGN KEY (fonte_id) REFERENCES fontes(id)
  );
`);

// Clear existing sources and re-seed
db.exec('DELETE FROM fontes');

const insert = db.prepare(`
  INSERT INTO fontes (nome, url_base, url_feed, tipo_coleta, regiao, seletor_titulo, seletor_imagem, seletor_link, seletor_container, prioridade)
  VALUES (@nome, @url_base, @url_feed, @tipo_coleta, @regiao, @seletor_titulo, @seletor_imagem, @seletor_link, @seletor_container, @prioridade)
`);

const insertMany = db.transaction((fontes: typeof fontesSeed) => {
  for (const fonte of fontes) {
    insert.run(fonte);
  }
});

insertMany(fontesSeed);

console.log(`✓ Database created at: ${DB_PATH}`);
console.log(`✓ ${fontesSeed.length} sources seeded successfully`);

db.close();
