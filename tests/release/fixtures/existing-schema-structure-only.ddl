-- Fictional schema fixture. Structure only. Contains no employee or taxpayer records.
CREATE TABLE schools (
 id VARCHAR(64) PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 data LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE members (
 id VARCHAR(64) PRIMARY KEY,
 email VARCHAR(180) NOT NULL,
 password_hash VARCHAR(255),
 school_id VARCHAR(64),
 CONSTRAINT fk_member_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;
CREATE TABLE suppliers (
 id VARCHAR(64) PRIMARY KEY,
 name VARCHAR(180) NOT NULL,
 tin VARCHAR(14) NOT NULL,
 data LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE source_funds (
 id VARCHAR(64) PRIMARY KEY,
 name VARCHAR(80) NOT NULL,
 data LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE transactions (
 id VARCHAR(64) PRIMARY KEY,
 school_id VARCHAR(64),
 supplier_id VARCHAR(64),
 gross BIGINT NOT NULL,
 snapshot LONGTEXT,
 CONSTRAINT fk_tx_school FOREIGN KEY (school_id) REFERENCES schools(id),
 CONSTRAINT fk_tx_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;
CREATE TABLE audit (
 id VARCHAR(64) PRIMARY KEY,
 actor VARCHAR(64) NOT NULL,
 details LONGTEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE schema_migrations (
 id VARCHAR(100) PRIMARY KEY,
 status VARCHAR(20) NOT NULL
) ENGINE=InnoDB;
DELIMITER $$
CREATE TRIGGER protect_audit_delete
 BEFORE DELETE ON audit FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit is immutable';
END$$
DELIMITER ;
