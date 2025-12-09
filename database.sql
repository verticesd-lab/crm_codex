CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_fantasia VARCHAR(150) NOT NULL,
    razao_social VARCHAR(150),
    slug VARCHAR(120) UNIQUE NOT NULL,
    whatsapp_principal VARCHAR(30),
    instagram_usuario VARCHAR(80),
    email VARCHAR(120),
    logo VARCHAR(255),
    favicon VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(120) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    role ENUM('admin','user') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    telefone_principal VARCHAR(30),
    whatsapp VARCHAR(30),
    instagram_username VARCHAR(80),
    email VARCHAR(120),
    tags VARCHAR(255),
    observacoes TEXT,
    ltv_total DECIMAL(12,2) DEFAULT 0,
    ultimo_atendimento_em DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_clients_company (company_id),
    INDEX idx_clients_whatsapp (whatsapp),
    INDEX idx_clients_instagram (instagram_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT,
    categoria VARCHAR(120),
    sizes VARCHAR(255),
    tipo ENUM('produto','servico') DEFAULT 'produto',
    preco DECIMAL(12,2) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    destaque TINYINT(1) DEFAULT 0,
    imagem VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_products_company (company_id),
    INDEX idx_products_category (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    client_id INT NOT NULL,
    canal ENUM('whatsapp','instagram','telefone','presencial','outro') DEFAULT 'whatsapp',
    origem ENUM('manual','loja','lp','ia') DEFAULT 'manual',
    titulo VARCHAR(150) NOT NULL,
    resumo TEXT,
    atendente VARCHAR(120),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_interactions_client (client_id),
    INDEX idx_interactions_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    client_id INT NULL,
    origem VARCHAR(60) DEFAULT 'loja',
    status ENUM('novo','em_andamento','concluido','cancelado') DEFAULT 'novo',
    total DECIMAL(12,2) DEFAULT 0,
    observacoes_cliente TEXT,
    notes_internas TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_orders_company (company_id),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantidade INT DEFAULT 1,
    preco_unitario DECIMAL(12,2) DEFAULT 0,
    subtotal DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    descricao TEXT,
    banner_image VARCHAR(255),
    texto_chamada VARCHAR(255),
    destaque_produtos VARCHAR(255),
    data_inicio DATE,
    data_fim DATE,
    ativo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_promotions_company (company_id),
    UNIQUE KEY uniq_promo_slug_company (company_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE action_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(120) NOT NULL,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_logs_company (company_id),
    INDEX idx_logs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuário admin inicial (troque a senha após criar)
INSERT INTO companies (nome_fantasia, slug, whatsapp_principal, instagram_usuario, email) VALUES ('Empresa Exemplo', 'minhaloja', '55999999999', 'minhaloja', 'contato@exemplo.com');
INSERT INTO users (company_id, nome, email, senha, role) VALUES (1, 'Admin', 'admin@exemplo.com', '$2y$10$fVH8e28OQRj9tqiDXs1e1ux7d7r8uC5kfXgcgF6CIM3pQ1/u2E1d2', 'admin');
