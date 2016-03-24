# Deployment Suite

## Sample output:

`php artisan deploy:status`:

```Shell
Checking local:
 5/5 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Repo status for local:
+-----------+-------------------------+-------+---------+--------+
| Service   | Branch                  | Tag   | Commit  | Status |
+-----------+-------------------------+-------+---------+--------+
| FRONTEND  | master                  | 1.1.2 | 06f2aa8 | CLEAN  |
| DEVICES   | master                  | 1.1.1 | d7e16f0 | CLEAN  |
| USERS     | master                  | 1.1.1 | 822e573 | CLEAN  |
| LOCATIONS | restructure-with-traits | 2.0.1 | 4aa4d5d | DIRTY  |
| AUDITING  | master                  | 1.1.1 | 7772db2 | CLEAN  |
+-----------+-------------------------+-------+---------+--------+

Checking dev:
 5/5 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Repo status for dev:
+-----------+-------------------------+-------+---------+--------+
| Service   | Branch                  | Tag   | Commit  | Status |
+-----------+-------------------------+-------+---------+--------+
| FRONTEND  | (HEADdetachedat1.1.2)   | 1.1.2 | 06f2aa8 | DIRTY  |
| DEVICES   | master                  |       | d7e16f0 | CLEAN  |
| USERS     | master                  |       | 822e573 | CLEAN  |
| LOCATIONS | restructure-with-traits |       | 4aa4d5d | CLEAN  |
| AUDITING  | master                  |       | 7772db2 | CLEAN  |
+-----------+-------------------------+-------+---------+--------+

Checking production:
 5/5 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Repo status for production:
+-----------+-------------------------+-----+---------+--------+
| Service   | Branch                  | Tag | Commit  | Status |
+-----------+-------------------------+-----+---------+--------+
| FRONTEND  | master                  |     | 8a9bef0 | DIRTY  |
| DEVICES   | master                  |     | d7e16f0 | CLEAN  |
| USERS     | master                  |     | 822e573 | CLEAN  |
| LOCATIONS | restructure-with-traits |     | 4aa4d5d | CLEAN  |
| AUDITING  | master                  |     | 7772db2 | DIRTY  |
+-----------+-------------------------+-----+---------+--------+
```
