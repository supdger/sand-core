#!/usr/bin/env python3
"""命名与注释规范 v2 的机械检查。FAIL 必须改，WARN 由验收人逐条判断。

  naming_check.py sql   [--allow F] 迁移.sql ...
  naming_check.py code  [--allow F] [--diff D] [源文件 ...]
  naming_check.py notes [--diff D] [源文件 ...]
  naming_check.py terms 词表.tsv [--allow F] [--diff D] [文件 ...]

--diff 读 `git diff -U0` 的输出（- 表示标准输入），只查新增的行。
"""
import re
import sys

TABLE_MAX = (3, 30)
COLUMN_MAX = (3, 30)
WORD_MAX = {"var": 3, "const": 4, "func": 4, "type": 3}
PG_NAME_BYTES = 63
RATIO_WARN = 0.15

CODE_EXT = (".php", ".ts", ".tsx", ".js", ".jsx", ".vue")
NOTE_EXT = CODE_EXT + (".sql",)
SKIP_DIR = re.compile(r"(^|/)(vendor|node_modules|dist|build|generated)/")

COLUMN_SUFFIX = {"id", "time", "date", "count", "hash", "code", "key", "by",
                 "ms", "seconds", "bytes", "percent"}
BOOL_PREFIX = {"is", "has", "can", "must"}
ROLE_SUFFIX = {"controller", "service", "repository", "middleware", "command",
               "exception", "interface", "trait", "factory", "provider",
               "validator", "policy", "event", "listener", "handler",
               "page", "modal", "drawer", "form", "table", "context"}
FREE_SUFFIX = {"test", "props"}
CALL_PREFIX = {"on", "handle", "use", "set"}
FILLER = {"manager", "helper", "util", "utils", "processor", "wrapper", "info", "data"}
VAGUE = {"temp", "tmp", "new", "final", "actual", "real", "processed", "updated"}
TYPE_SUFFIX = {"str", "string", "obj", "object", "arr", "array", "list"}
BAD_ABBR = {"usr", "pwd", "passwd", "cnt", "msg", "btn", "tbl", "mgr", "svc", "cfg",
            "val", "ret", "lbl", "addr", "qty", "amt", "flg", "prefs", "qb"}
PHP_SKIP = {"$this", "$_GET", "$_POST", "$_SERVER", "$_COOKIE", "$_FILES", "$_ENV",
            "$_SESSION", "$GLOBALS"}
VUE_TOKEN = re.compile(r"<!--|-->|<(/?)script\b[^>]*>", re.I)

failed = False


def report(level, rule, where, name, why):
    global failed
    failed = failed or level == "FAIL"
    print(f"{level}\t{rule}\t{where}\t{name}\t{why}")


def words(name):
    parts = re.split(r"_+|-|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])", name.strip("$_"))
    return [p.lower() for p in parts if p]


def read_allow(path):
    if not path:
        return set()
    with open(path, encoding="utf-8") as f:
        return {line.split("#")[0].strip() for line in f} - {""}


def take_option(args, flag):
    if flag not in args:
        return None
    i = args.index(flag)
    value = args[i + 1]
    del args[i:i + 2]
    return value


def added_lines(diff_path):
    stream = sys.stdin if diff_path == "-" else open(diff_path, encoding="utf-8", errors="replace")
    added, path, number = {}, None, 0
    for line in stream:
        if line.startswith("+++ "):
            path = line[4:].strip()
            path = path[2:] if path.startswith("b/") else path
        elif line.startswith("@@"):
            number = int(re.search(r"\+(\d+)", line).group(1))
        elif line.startswith("+") and path:
            added.setdefault(path, []).append((number, line[1:].rstrip("\n")))
            number += 1
    return added


def load(paths, diff_path, extensions):
    if diff_path:
        added = added_lines(diff_path)
        if paths:
            return {p: added.get(p, []) for p in paths}
        return {p: rows for p, rows in added.items() if p.endswith(extensions) and not SKIP_DIR.search(p)}
    files = {}
    for p in paths:
        with open(p, encoding="utf-8", errors="replace") as f:
            files[p] = list(enumerate(f.read().split("\n"), 1))
    return files


def split_top(body):
    parts, depth, start, quoted = [], 0, 0, False
    for i, ch in enumerate(body):
        if ch == "'":
            quoted = not quoted
        elif quoted:
            continue
        elif ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        elif ch == "," and depth == 0:
            parts.append(body[start:i])
            start = i + 1
    parts.append(body[start:])
    return [p.strip() for p in parts if p.strip()]


def table_body(sql, open_at):
    depth, quoted = 0, False
    for i in range(open_at, len(sql)):
        ch = sql[i]
        if ch == "'":
            quoted = not quoted
        elif quoted:
            continue
        elif ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return sql[open_at + 1:i]
    return sql[open_at + 1:]


def check_table(table, allow):
    if table in allow:
        return
    m = re.fullmatch(r"sand_[a-z0-9]+_([a-z0-9_]+)", table)
    if not m:
        report("FAIL", "表名格式", table, table, "应为 sand_<模块>_<名词>，小写蛇形")
        return
    core = m.group(1)
    count = len(core.split("_"))
    if count > TABLE_MAX[0] or len(core) > TABLE_MAX[1]:
        report("FAIL", "表名长度", table, core, f"去前缀后 {count} 词 {len(core)} 字符，上限 {TABLE_MAX}")
    if re.search(r"[^s]s$", core) and not re.search(r"(status|address|access|process|class|alias|analysis|series)$", core):
        report("WARN", "表名单数", table, core, "看着像复数")


def check_column(table, column, kind, allow):
    where = f"{table}.{column}"
    if column in allow or where in allow:
        return
    if not re.fullmatch(r"[a-z][a-z0-9_]*", column):
        report("FAIL", "列名格式", where, column, "应为小写蛇形")
        return
    body = column.split("_")
    if body[0] in BOOL_PREFIX:
        body = body[1:]
    if len(body) > 1 and body[-1] in COLUMN_SUFFIX:
        body = body[:-1]
    if len(body) > COLUMN_MAX[0] or len(column) > COLUMN_MAX[1]:
        report("FAIL", "列名长度", where, column, f"主体 {len(body)} 词、全长 {len(column)}，上限 {COLUMN_MAX}")
    core = re.sub(r"^sand_[a-z0-9]+_", "", table)
    if column.startswith(core + "_"):
        report("FAIL", "列名重复表名", where, column, f"去掉开头的 {core}_")
    if re.search(r"_(at|timestamp|datetime)$", column):
        report("FAIL", "时间列后缀", where, column, "时间点用 _time，日期用 _date")
    elif kind.startswith("timestamp") and not column.endswith("_time"):
        report("FAIL", "时间列后缀", where, column, "timestamptz 列以 _time 结尾")
    elif re.match(r"date\b", kind) and not column.endswith("_date"):
        report("FAIL", "时间列后缀", where, column, "date 列以 _date 结尾")
    if kind.startswith("bool") and (re.search(r"_(flag|status|state|bool|yn)$", column)
                                    or re.match(r"(is_not|not|no|non)_", column)):
        report("FAIL", "布尔列", where, column, "不用 _flag 一类后缀，不用否定式")
    if set(column.split("_")) & BAD_ABBR:
        report("FAIL", "自造缩写", where, column, "写全称，或先把缩写加进缩写表")


def check_constraint(table, name, prefix, allow):
    if name in allow:
        return
    size = len(name.encode())
    if size > PG_NAME_BYTES:
        report("FAIL", "约束名超长", table, name, f"{size} 字节，PostgreSQL 上限 {PG_NAME_BYTES}")
    if prefix == "pk" and name in (f"{table}_pkey", f"pk_{table}"):
        return
    if not re.fullmatch(rf"{prefix}_{re.escape(table)}_[a-z0-9_]+", name):
        report("FAIL", "约束名格式", table, name, f"应为 {prefix}_{table}_<列或用途>")


def check_sql(paths, allow):
    kinds = {"UNIQUE": "uk", "CHECK": "ck", "FOREIGN": "fk", "REFERENCES": "fk", "PRIMARY": "pk"}
    named = r"CONSTRAINT\s+\"?(\w+)\"?\s+(UNIQUE|CHECK|FOREIGN|REFERENCES|PRIMARY)\b"
    create = r"CREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\"?(\w+)\"?\s*\("
    alter = r"ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?\"?(\w+)\"?(.*?);"
    add_column = r"ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?\"?(\w+)\"?\s+([a-z][\w() ]*)"
    index = (r"CREATE\s+(UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?"
             r"\"?(\w+)\"?\s+ON\s+(?:ONLY\s+)?\"?(\w+)\"?")
    for path in paths:
        with open(path, encoding="utf-8") as f:
            sql = re.sub(r"/\*.*?\*/", "", re.sub(r"--[^\n]*", "", f.read()), flags=re.S)
        for m in re.finditer(create, sql, re.I):
            table = m.group(1)
            check_table(table, allow)
            for item in split_top(table_body(sql, m.end() - 1)):
                for found in re.finditer(named, item, re.I):
                    check_constraint(table, found.group(1), kinds[found.group(2).upper()], allow)
                column = re.match(r"\"?(\w+)\"?\s+(.*)", item, re.S)
                if column and not re.match(r"(CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN|EXCLUDE|LIKE)\b", item, re.I):
                    check_column(table, column.group(1), column.group(2).lower(), allow)
        for m in re.finditer(alter, sql, re.I | re.S):
            for found in re.finditer(r"ADD\s+" + named, m.group(2), re.I):
                check_constraint(m.group(1), found.group(1), kinds[found.group(2).upper()], allow)
            for found in re.finditer(add_column, m.group(2), re.I):
                if found.group(1).upper() not in ("CONSTRAINT", "COLUMN", "PRIMARY", "UNIQUE", "CHECK", "FOREIGN"):
                    check_column(m.group(1), found.group(1), found.group(2).lower(), allow)
        for m in re.finditer(index, sql, re.I):
            check_constraint(m.group(3), m.group(2), "uk" if m.group(1) else "ix", allow)


def declarations(path, text):
    found = []
    if path.endswith(".php"):
        found += [("type", n) for n in re.findall(r"\b(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)", text)]
        found += [("func", n) for n in re.findall(r"\bfunction\s+&?([A-Za-z_]\w*)\s*\(", text)]
        found += [("const", n) for n in re.findall(r"\bconst\s+(?:[\w\\?|]+\s+)?([A-Z][A-Z0-9_]*)\s*=", text)]
        found += [("var", n) for n in re.findall(r"\$[A-Za-z_]\w*", text) if n not in PHP_SKIP]
        return found
    found += [("type", n) for n in re.findall(r"\b(?:class|interface|type|enum)\s+([A-Za-z_$][\w$]*)", text)]
    found += [("func", n) for n in re.findall(r"\bfunction\s*\*?\s+([A-Za-z_$][\w$]*)", text)]
    arrow = (r"\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*(?::[^=]+)?=\s*(?:async\s*)?"
             r"(?:\(|function\b|[A-Za-z_$][\w$]*\s*=>)")
    functions = set(re.findall(arrow, text))
    for n in re.findall(r"\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)", text):
        found.append(("func" if n in functions else "var", n))
    for group in re.findall(r"\b(?:const|let|var)\s+[\[{]([^=;]*?)[\]}]\s*=", text):
        for part in group.split(","):
            n = part.split(":")[-1].split("=")[0].replace("...", "").strip()
            if re.fullmatch(r"[A-Za-z_$][\w$]*", n):
                found.append(("var", n))
    return found


def check_name(kind, name, where, siblings, one_liner):
    tokens = words(name)
    if not tokens:
        return
    if kind == "func" and name[:1].isupper():
        kind = "type"
    if kind == "var" and len(name) > 1 and re.fullmatch(r"[A-Z][A-Z0-9_]*", name):
        kind = "const"
    is_test = kind == "func" and tokens[0] == "test"
    body = tokens[:]
    if kind == "type":
        if len(body) > 1 and body[-1] in FREE_SUFFIX:
            body = body[:-1]
        if len(body) > 1 and body[-1] in ROLE_SUFFIX:
            body = body[:-1]
    elif len(body) > 1 and body[0] in CALL_PREFIX:
        body = body[1:]
    if not is_test and len(body) > WORD_MAX[kind]:
        report("FAIL", "词数超限", where, name, f"{kind} 主体 {len(body)} 词，上限 {WORD_MAX[kind]}")
    if len(body) > 1 and body[-1] in FILLER:
        report("FAIL", "空泛后缀", where, name, f"去掉 {body[-1]}，或换成具体名词")
    elif len(body) > 1 and set(body[:-1]) & FILLER:
        report("WARN", "空泛词", where, name, "名字中间有 Data、Info 一类的词")
    if set(tokens) & BAD_ABBR:
        report("FAIL", "自造缩写", where, name, "写全称，或先把缩写加进缩写表")
    if kind == "var" and len(tokens) > 1 and tokens[-1] in TYPE_SUFFIX:
        report("FAIL", "类型后缀", where, name, "去掉类型词，集合用复数")
    if kind in ("var", "func") and not is_test and set(tokens) & VAGUE:
        report("WARN", "含糊修饰", where, name, "成对的 old/new、契约字段可以保留，其余换成说清区别的词")
    numbered = re.fullmatch(r"(\$?[A-Za-z_]*[A-Za-z_])\d", name)
    if numbered and numbered.group(1) in siblings:
        report("FAIL", "数字后缀", where, name, f"和 {numbered.group(1)} 只差一个数字")
    letter = name.strip("$")
    if kind == "var" and len(letter) == 1 and letter not in "ijkexyabt_" and not one_liner:
        report("WARN", "单字母", where, name, "只有 i/j/k、e、x/y、a/b、t 和一行写完的回调可以用单字母")


def script_indexes(texts):
    """texts 中属于 <script> 段正文的下标；标签行兼有正文时也算。
    script 段外的 HTML 注释（可跨行）整体跳过，注释里的 <script> 不算标签；
    script 段内不识别注释，</script> 按 raw text 语义即闭合。"""
    keep, inside, comment = set(), False, False
    for i, raw in enumerate(texts):
        pos = 0
        for m in VUE_TOKEN.finditer(raw):
            tok = m.group(0)
            if comment:
                if tok == "-->":
                    comment = False
                continue
            if tok == "<!--":
                if not inside:
                    comment = True
                continue
            if tok == "-->":
                continue
            if inside and raw[pos:m.start()].strip():
                keep.add(i)
            inside = not m.group(1)
            pos = m.end()
        if inside:
            keep.add(i)
    return keep


def vue_script_rows(path, lines):
    """code 检查用：只留 .vue <script> 段，行号不变。
    --diff 只给新增行、行里常没有标签，段归属按磁盘文件判定。"""
    try:
        with open(path, encoding="utf-8", errors="replace") as f:
            keep = {i + 1 for i in script_indexes(f.read().split("\n"))}
        return [(n, t) for n, t in lines if n in keep]
    except OSError:
        keep = script_indexes([t for _, t in lines])
        return [row for i, row in enumerate(lines) if i in keep]


def vue_note_rows(lines):
    """notes 检查用：.vue 全文保留行号，HTML 注释改写成块注释交给 classify。"""
    return [(n, raw.replace("<!--", "/*").replace("-->", "*/")) for n, raw in lines]


def check_code(files, allow):
    for path, lines in files.items():
        if path.endswith(".vue"):
            lines = vue_script_rows(path, lines)
        rows = [(number, lines[i][1]) for i, (number, kind, _) in enumerate(classify(path, lines)) if kind == "code"]
        per_line = [(number, text, declarations(path, text)) for number, text in rows]
        names = {n for _, _, found in per_line for _, n in found}
        seen = set()
        for number, text, found in per_line:
            one_liner = bool(re.search(r"\bfn\s*\(|=>", text))
            for kind, name in found:
                if (kind, name) in seen or name in allow:
                    continue
                seen.add((kind, name))
                check_name(kind, name, f"{path}:{number}", names, one_liner)


TAG_ONLY = re.compile(r"^(@(param|return|returns|var|throws|template|extends|implements|deprecated|see|"
                      r"phpstan|psalm|internal)\b|eslint-|@ts-|phpcs:|prettier-ignore|c8 ignore|"
                      r"istanbul ignore|noinspection)")
BAD_NOTE = [
    ("WARN", "流水账", re.compile(r"第\s*[一二三四五六七八九十0-9]+\s*步|步骤\s*[一二三四五六七八九十0-9]|\bstep\s*\d+\b", re.I)),
    ("FAIL", "废话开头", re.compile(r"^(此|该|这个|本)(函数|方法|类|接口|组件|文件)(用于|用来|是|负责)|^用于|"
                                 r"^(this|the) (function|method|class|component|hook|file) "
                                 r"(is used to|is responsible|will|handles)", re.I)),
    ("FAIL", "横幅分隔线", re.compile(r"[-=*#~_/]{6,}|^[-=*#~]{3,}.*[-=*#~]{3,}$")),
    ("FAIL", "表情符号", re.compile("[\U0001F000-\U0001FAFF\u2600-\u27BF\u2B50\u2B55]")),
    ("FAIL", "AI 署名", re.compile(r"generated (by|with)|co-authored-by|@author\s+(devin|claude|chatgpt|copilot|ai)\b", re.I)),
    ("WARN", "改动记录", re.compile(r"修复了|已修复|原来是|原先|之前是|按照?(要求|需求|审计|评审)|根据(用户|审计|评审|需求)|"
                                 r"\bP[0-2]-\d+\b|as requested|per (the )?(review|request|audit)|"
                                 r"changed from|previously", re.I)),
    ("WARN", "注释掉的代码", re.compile(r"^(\$\w+\s*=|(if|for|foreach|while|return|const|let|var|function|import)\b.*[;{(]$|.*\);$)")),
]
TODO = re.compile(r"\b(TODO|FIXME|XXX|HACK)\b")
TODO_OK = re.compile(r"\b(TODO|FIXME)\((#\d+|[\w.-]+)\)[:：]\s*\S")
LICENSE = re.compile(r"copyright|spdx|license|版权|许可", re.I)
PREAMBLE = re.compile(r"^(<\?php|declare\s*\(.*\);|#!.*|['\"]use (strict|client)['\"];?)$")
CLASS_LIKE = re.compile(r"^(export\s+)?(default\s+)?(abstract\s+|final\s+|readonly\s+)*(class|interface|trait|enum)\b")


def classify(path, lines):
    line_mark = "--" if path.endswith(".sql") else "//"
    rows, inside = [], False
    for number, raw in lines:
        s = raw.strip()
        if not s:
            rows.append((number, "blank", ""))
        elif inside or s.startswith("/*") or s.startswith("{/*"):
            inside = "*/" not in s
            rows.append((number, "note", re.sub(r"^\{?/\*+|\*+/\}?$|^\*+", "", s).strip()))
        elif re.match(r"\*(/|\s|$)", s):
            rows.append((number, "note", s.strip("*/ ")))
        elif s.startswith(line_mark):
            rows.append((number, "note", s[len(line_mark):].strip()))
        elif path.endswith(".php") and s.startswith("#") and not s.startswith("#["):
            rows.append((number, "note", s[1:].strip()))
        else:
            tail = re.search(rf"(?<=\s){re.escape(line_mark)}\s?(.*)$", s)
            rows.append((number, "code", tail.group(1).strip() if tail else ""))
    return rows


def check_notes(files, partial):
    totals = {}
    for path, lines in files.items():
        if path.endswith(".vue"):
            lines = vue_note_rows(lines)
        rows = classify(path, lines)
        first_code = next((i for i, row in enumerate(rows)
                           if row[1] == "code" and not PREAMBLE.match(lines[i][1].strip())), len(rows))
        head = [i for i in range(first_code) if rows[i][1] == "note"]
        licensed = set(head) if any(LICENSE.search(rows[i][2]) for i in head) else set()
        counted = totals.setdefault(path.rsplit(".", 1)[-1], [0, 0])
        prose, start = 0, None
        for i, (number, kind, body) in enumerate(rows):
            where = f"{path}:{number}"
            if kind == "code":
                counted[1] += 1
            if i in licensed or kind == "blank":
                continue
            if kind == "note":
                plain = bool(body) and not TAG_ONLY.match(body)
                if plain:
                    counted[0] += 1
                    start = start or number
                    prose += 1
                if i + 1 >= len(rows) or rows[i + 1][1] != "note":
                    target = lines[i + 1][1].strip() if i + 1 < len(lines) else ""
                    limit = 6 if partial or not target or CLASS_LIKE.match(target) else 3
                    if prose > limit:
                        report("WARN", "注释过长", f"{path}:{start}", f"{prose} 行", f"说明文字上限 {limit} 行（@标签不计）")
                    prose, start = 0, None
            if not body:
                continue
            for level, rule, pattern in BAD_NOTE:
                if pattern.search(body):
                    report(level, rule, where, body[:40], "见规范第五节")
            if TODO.search(body) and not TODO_OK.search(body):
                report("FAIL", "TODO 格式", where, body[:40], "写成 TODO(负责人或#编号): 原因")
    for ext, (notes, code) in sorted(totals.items()):
        ratio = notes / code if code else 0
        level = "WARN" if ratio > RATIO_WARN and ext != "sql" and code >= 50 else "INFO"
        print(f"{level}\t注释占比\t*.{ext}\t{ratio:.1%}\t说明性注释 {notes} 行 / 代码 {code} 行（许可证头、@标签、工具指令不计）")


def check_terms(glossary, files, allow):
    banned = {}
    with open(glossary, encoding="utf-8") as f:
        for line in f:
            if line.strip() and not line.startswith("#"):
                standard, _, others = line.rstrip("\n").partition("\t")
                for other in others.split(","):
                    if other.strip():
                        banned["_".join(words(other.strip()))] = standard.strip()
    for path, lines in files.items():
        for number, text in lines:
            for name in sorted(set(re.findall(r"[A-Za-z_$][\w$-]*[A-Za-z0-9]", text))):
                snake = "_".join(words(name))
                if name in allow or snake in allow:
                    continue
                for other, standard in banned.items():
                    if re.search(rf"(^|_){re.escape(other)}(s|es)?(_|$)", snake):
                        report("FAIL", "同义词", f"{path}:{number}", name, f"统一用 {standard}")


def main():
    args = sys.argv[1:]
    if not args or args[0] not in ("sql", "code", "notes", "terms"):
        sys.exit(__doc__)
    mode, args = args[0], args[1:]
    allow = read_allow(take_option(args, "--allow"))
    diff = take_option(args, "--diff")
    if mode == "sql":
        check_sql(args, allow)
    elif mode == "code":
        check_code(load(args, diff, CODE_EXT), allow)
    elif mode == "notes":
        check_notes(load(args, diff, NOTE_EXT), bool(diff))
    else:
        check_terms(args[0], load(args[1:], diff, NOTE_EXT + (".yaml", ".yml", ".json", ".md")), allow)
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
