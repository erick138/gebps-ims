-- Reference data and the first super administrator.
-- Default sign-in:  username  superadmin   password  ChangeMe!2026
-- The account is flagged must_change_password, so the system forces a new password at first sign-in.

INSERT IGNORE INTO categories (code, name) VALUES
  ('IT',  'ICT Equipment'),
  ('FUR', 'Furniture & Fixtures'),
  ('AV',  'Audio-Visual'),
  ('LAB', 'Laboratory Equipment'),
  ('SPT', 'Sports Equipment'),
  ('OFF', 'Office Equipment'),
  ('APP', 'Appliances');

-- bcrypt hash (cost 12) generated with PHP password_hash(); PHP password_verify() reads it directly
INSERT IGNORE INTO users (username, email, name, password_hash, role, status, position, must_change_password)
VALUES ('superadmin', 'superadmin@gebpsoics.edu.ph', 'Super Administrator',
        '$2y$12$o2ZrjB5xxorhWCv18/j7/e4nP5nKKBfrVzEbeo/ot8.qUHpY1oeca',
        'superadmin', 'active', 'System Owner', 1);
