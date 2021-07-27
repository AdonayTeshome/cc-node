CREATE TABLE transactions (
  id int(11) NOT NULL,
  uuid varchar(40) NOT NULL,
  version int(2) NOT NULL DEFAULT 1,
  type varchar(16) NOT NULL,
  scribe varchar(64) NOT NULL comment 'The account which wrote this version of the transaction.',
  state enum('pending', 'completed', 'erased', 'timedout') NOT NULL,
  payee_hash varchar(40) DEFAULT NULL comment '128 is for debugging, hashes will 40 chars',
  payer_hash varchar(40) DEFAULT NULL comment '128 is for debugging, hashes will 40 chars',
  written DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE transactions
  ADD PRIMARY KEY (id);

ALTER TABLE transactions
  MODIFY id int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE entries (
  id int(11) NOT NULL,
  txid int(11) NOT NULL,
  payee varchar(64) NOT NULL,
  payer varchar(64) NOT NULL,
  description tinytext NOT NULL,
  quant float NOT NULL,
  author varchar(32) NOT NULL,
  is_primary int(1) NOT NULL DEFAULT 0,
  metadata text(1024) COMMENT 'serialised array'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT = 'These are written once and theoretically never change';

ALTER TABLE entries
  ADD PRIMARY KEY (id);

ALTER TABLE entries
  MODIFY id int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE temp (
  uuid varchar(40) NOT NULL,
  serialized MEDIUMBLOB NOT NULL,
  written DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'to be cleared by cron'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='for initiated and validated transactions';

ALTER TABLE temp
  ADD PRIMARY KEY (uuid);

# A list of hashes from which to validate against incoming requests
DROP VIEW IF EXISTS hash_history;
CREATE ALGORITHM = UNDEFINED VIEW hash_history AS
  SELECT t.id, e.payer as acc, t.payer_hash as hash
    FROM transactions t JOIN entries e on t.id = e.txid and e.is_primary = 1
    WHERE payer_hash <> ''
UNION
  SELECT t.id, e.payee as acc, t.payee_hash as hash
    FROM transactions t JOIN entries e on t.id = e.txid and e.is_primary = 1
    WHERE payee_hash <> '';


# This view helps filters out superannuated transactions.
DROP VIEW IF EXISTS versions;
CREATE ALGORITHM = UNDEFINED VIEW versions AS
SELECT uuid, max(transactions.`version`) AS `ver`
FROM `transactions` GROUP BY `uuid`;

# This view of the ledger is helpful for getting user-centric data.
# except that it's not currently used
DROP VIEW IF EXISTS transaction_index;
CREATE ALGORITHM = UNDEFINED VIEW transaction_index AS
  SELECT
      t.id,
      t.uuid,
      e.payee AS uid1,
      e.payer AS uid2,
      t.type,
      t.state,
      e.quant AS income,
      0 AS expenditure,
      + e.quant AS diff,
      e.quant AS volume,
      t.written,
      e.is_primary
  FROM transactions t
    INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver
    INNER JOIN entries e ON t.id = e.txid
  WHERE state in ('pending', 'completed')
UNION
  SELECT
      t.id,
      t.uuid,
      e.payer AS uid1,
      e.payee AS uid2,
      t.type,
      t.state,
      0 AS income,
      e.quant AS expenditure,
      - e.quant AS diff,
      e.quant AS volume,
      t.written,
      e.is_primary
  FROM transactions t
    INNER JOIN versions v ON t.uuid = v.uuid AND t.version = v.ver
    INNER JOIN entries e ON t.id = e.txid
  WHERE state in ('pending', 'completed');


CREATE TABLE log (
  id int(11) NOT NULL,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  type enum('request','warning','serverError','clientError') NOT NULL DEFAULT 'request',
  http_method tinytext NOT NULL,
  path tinytext NOT NULL,
  message text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE log
  ADD PRIMARY KEY (id);

ALTER TABLE log
  MODIFY id int(11) NOT NULL AUTO_INCREMENT;