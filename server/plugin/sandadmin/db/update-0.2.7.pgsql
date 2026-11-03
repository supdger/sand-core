-- SandAdmin Core 0.2.7：首次登录强制改密标志。
-- 幂等：可重复执行。升级已有部署时，在目标 PostgreSQL 数据库执行一次；
-- 未执行前，登录与鉴权按"无需改密"处理，不会影响现有账号。
ALTER TABLE "sand_system_user"
  ADD COLUMN IF NOT EXISTS "must_change_password" smallint NOT NULL DEFAULT 0;

-- 旧部署中仍携带已知默认口令哈希的账号：标记首次登录强制改密。
-- 哈希取自 0.2.7 之前种子数据（admin、martin、allen、mark、dowson、
-- timi_boss、dev_wang、dev_li 等账号的全部去重默认口令哈希），按精确等值匹配；
-- 只置标志、不修改密码本身，与本文件其余语句一样可重复执行。
UPDATE "sand_system_user"
  SET "must_change_password" = 1
  WHERE "password" IN (
    '$2y$10$wnixh48uDnaW/6D9EygDd.OHJK0vQY/4nHaTjMKBCVDBP2NiTatqS',
    '$2y$10$J3EkwRH8rNkveaanx1.j.ebRiBpnnVUGWa.i2MS3aNpb9ydAOolmm',
    '$2y$10$H8d7riOjOiwPSopguEQ1fuKZz.fA0A54OvuzTqgJlbG1N3uOxEwM.',
    '$2y$10$sY/4StKVV.N/8Ock8J8kdeIOK4jS4tAUoYjkzvB8Tzy0fLh.wA2KS'
  );
