-- Executado apenas na primeira subida do volume do MySQL.
-- Um banco por sistema, cada usuário com acesso só ao próprio (ADR-002 e ADR-005).

-- Monólito (o usuário estuda_fit_hub é criado pelas variáveis MYSQL_USER/MYSQL_DATABASE)
CREATE DATABASE IF NOT EXISTS estuda_fit_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON estuda_fit_hub.* TO 'estuda_fit_hub'@'%';
GRANT ALL PRIVILEGES ON estuda_fit_hub_test.* TO 'estuda_fit_hub'@'%';

-- Serviço de Cobrança
CREATE DATABASE IF NOT EXISTS cobranca CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS cobranca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'cobranca'@'%' IDENTIFIED BY 'cobranca';
GRANT ALL PRIVILEGES ON cobranca.* TO 'cobranca'@'%';
GRANT ALL PRIVILEGES ON cobranca_test.* TO 'cobranca'@'%';

FLUSH PRIVILEGES;
