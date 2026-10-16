#!/usr/bin/env bash
# Verifies the seed fixtures: F1/F2 membership counts and F3 ACL behaviour.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
F3=$(python3 -c 'import json;print(json.load(open("results/seed_folders.json"))["folders"]["gf-acl"])')
fail=0
check() { # DESC EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected $2, got $3"; fail=1; fi
}
n1=$($OCC user:info u_narrow --output=json | python3 -c 'import sys,json;print(len(json.load(sys.stdin)["groups"]))')
n2=$($OCC user:info u_admin200 --output=json | python3 -c 'import sys,json;print(len(json.load(sys.stdin)["groups"]))')
check "F1 u_narrow in 1 group" 1 "$n1"
check "F2 u_admin200 in 200 groups" 200 "$n2"
ng=$($OCC group:list --output=json | python3 -c 'import sys,json;print(sum(1 for g in json.load(sys.stdin) if g.startswith("g") and g[1:].isdigit()))')
check "200 numbered groups" 200 "$ng"
nf=$($OCC groupfolders:list --output=json | python3 -c 'import sys,json;print(len(json.load(sys.stdin)))')
check "101 groupfolders (100 + gf-acl)" 101 "$nf"
# --test prints the effective permissions string; read must be present or absent.
perm() { $OCC groupfolders:permissions "$F3" "$2" -u "$1" --test 2>&1 | tr -d '\n'; }
p=$(perm u_narrow restricted/inner/deep/secret.txt); case "$p" in *"+read"*) r=read;; *) r=none;; esac
# Inversion guard: the test output wording is checked against the open path too.
q=$(perm u_narrow open/doc.txt); case "$q" in *"+read"*) o=read;; *) o=none;; esac
check "F3 u_narrow denied under /restricted" none "$r"
check "F3 u_narrow reads /open" read "$o"
p2=$(perm u_admin200 restricted/inner/deep/secret.txt); case "$p2" in *"+read"*) r2=read;; *) r2=none;; esac
check "F3 u_admin200 reads /restricted" read "$r2"
nt=$($C exec -T db psql -U nextcloud -d nextcloud -tAc "select count(*) from oc_systemtag_object_mapping m join oc_systemtag t on t.id=m.systemtagid where t.name='rag-excluded'" | tr -d '\r')
check "rag-excluded tag mapped once" 1 "$nt"
exit $fail
