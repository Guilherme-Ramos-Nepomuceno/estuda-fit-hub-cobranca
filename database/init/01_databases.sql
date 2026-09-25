-- Executado apenas na primeira subida do volume do MySQL.
CREATE DATABASE IF NOT EXISTS estuda_fit_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON estuda_fit_hub.* TO 'estuda_fit_hub'@'%';
GRANT ALL PRIVILEGES ON estuda_fit_hub_test.* TO 'estuda_fit_hub'@'%';
FLUSH PRIVILEGES;
