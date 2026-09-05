-- One account, reachable by several sign-in providers.
--
-- Before this, {users} carried a single auth_provider/auth_subject pair and
-- findOrCreateFromAuth matched on EMAIL — so signing in with Google and then
-- with Microsoft on the same address rebound the account to whichever was used
-- last, and the person's identity at the first provider was forgotten.
--
-- THE RULE THIS TABLE EXISTS TO ENFORCE
--
-- An identity attaches to an existing account only when the provider says the
-- address is VERIFIED. Anything else is refused.
--
-- Without that rule an email match is an account takeover: anybody who can get
-- any configured provider to assert an address — and a provider that lets you
-- type one unverified is enough — inherits the account that already holds it,
-- with all of its history and permissions. That is the shape the previous code
-- had, and it was reachable the moment a site offered a second provider.
--
-- WHY THE SUBJECT IS THE KEY AND THE EMAIL IS NOT
--
-- A subject is the provider's own stable identifier for a person and never
-- changes. An email address does: people are renamed, addresses are reassigned
-- inside an organisation, and a provider may hand back an address that used to
-- belong to somebody else. So a returning person is found by
-- (provider, subject), and the address is only ever used to decide whether a
-- FIRST link may be made.
--
-- UNIQUE(provider, subject) is what stops one identity being claimed by two
-- accounts — which is the state that makes "who is this person" unanswerable.
--
-- Local accounts are recorded here too, with provider 'local'. They have no
-- third party to vouch for anything, so verified_at is whatever the site
-- itself recorded; keeping them in the same table means one place answers
-- "how can this person sign in", rather than one place plus a special case.

CREATE TABLE IF NOT EXISTS {user_identities} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,

  -- 'local', 'auth0', 'oidc', 'google' — how they signed in.
  provider    VARCHAR(64)  NOT NULL,

  -- The provider's own identifier. Opaque, stable, and the thing actually
  -- matched on.
  subject     VARCHAR(255) NOT NULL,

  -- The address the provider asserted at the time, kept for the audit trail
  -- rather than for matching: it is what an administrator needs when asked why
  -- an account has an identity nobody recognises.
  email       VARCHAR(191) NULL,

  -- When the provider last said the address was verified. NULL means it never
  -- did, which is a different thing from "not yet checked" only in the sense
  -- that neither may be attached to an existing account.
  verified_at DATETIME NULL,

  created_at  DATETIME NOT NULL,
  last_seen_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_identity (provider, subject),
  KEY idx_user (user_id),

  CONSTRAINT fk_identity_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from what {users} already holds, so nobody has to sign in twice to
-- be recognised after this upgrade.
--
-- verified_at follows the account's own email_verified flag: it is the only
-- evidence this site has about an identity created before the table existed,
-- and inventing a verification nobody performed would be worse than recording
-- none. An unverified one still signs in — it is found by subject, and the
-- verification rule only governs attaching a NEW identity to an account.
INSERT IGNORE INTO {user_identities} (user_id, provider, subject, email, verified_at, created_at)
SELECT id,
       COALESCE(auth_provider, 'oidc'),
       auth_subject,
       email,
       CASE WHEN email_verified = 1 THEN COALESCE(created_at, NOW()) ELSE NULL END,
       COALESCE(created_at, NOW())
  FROM {users}
 WHERE auth_subject IS NOT NULL AND auth_subject <> '';
