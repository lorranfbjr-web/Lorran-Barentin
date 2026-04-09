import Database from 'better-sqlite3';
import path from 'path';

const DB_PATH = path.join(process.cwd(), 'data', 'news.db');

let _db: Database.Database | null = null;

export function getDb(): Database.Database {
  if (!_db) {
    _db = new Database(DB_PATH);
    _db.pragma('journal_mode = WAL');
    _db.pragma('foreign_keys = ON');
    initSchema(_db);
  }
  return _db;
}

function initSchema(db: Database.Database) {
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
}

export interface Fonte {
  id: number;
  nome: string;
  url_base: string;
  url_feed: string | null;
  tipo_coleta: 'rss' | 'scraping' | 'sitemap';
  regiao: string;
  seletor_titulo: string | null;
  seletor_imagem: string | null;
  seletor_link: string | null;
  seletor_container: string | null;
  prioridade: 'alta' | 'normal';
  ativo: number;
  falhas_consecutivas: number;
  ultima_coleta: string | null;
}

export interface Noticia {
  id: number;
  fonte_id: number;
  titulo: string;
  url: string;
  imagem_url: string | null;
  data_publicacao: string | null;
  data_coleta: string;
  fonte_nome?: string;
  fonte_regiao?: string;
}
