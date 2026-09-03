-- What the identity provider said about this account, kept so it can be
-- re-checked without one.
--
-- The third gate. A site can require that whoever signs in carries a
-- particular claim with a particular value — an Auth0 organization (`org_id`),
-- a Google Workspace domain (`hd`), an Azure tenant (`tid`). Membership of
-- something, asserted by the provider rather than by this site.
--
-- WHY IT IS STORED RATHER THAN READ FROM THE TOKEN
--
-- The claim arrives in a verified ID token, which exists for one request at
-- sign-in and is gone afterwards. Checking it only there would mean the answer
-- lives in the session — so removing an organization from the accepted list
-- would refuse nobody until their cookie happened to expire, which is exactly
-- the cookie-dependent revocation this whole model exists to avoid.
--
-- Written on every sign-in, so it is never staler than the last authentication,
-- and read on every request, so narrowing the accepted list takes effect on the
-- next page load.
--
-- NULLABLE, and null is meaningful: the provider did not assert the claim at
-- all. That is a different thing from asserting a value nobody accepts — one is
-- usually a scope or a dashboard setting, the other is a person in the wrong
-- organization — and they need different fixes, so the gate reports them
-- separately.
--
-- RE-RUNNABLE, per 0021. `ADD COLUMN IF NOT EXISTS` is MariaDB-only and MySQL 8
-- rejects it, hence information_schema and a prepared statement.

SET @needs_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{users}', '`', '')
     AND COLUMN_NAME = 'auth_claim'
);

SET @ddl := IF(
  @needs_column,
  CONCAT('ALTER TABLE ', '{users}', ' ADD COLUMN auth_claim VARCHAR(191) NULL AFTER auth_subject'),
  'DO 0'
);

PREPARE add_auth_claim FROM @ddl;
EXECUTE add_auth_claim;
DEALLOCATE PREPARE add_auth_claim;
