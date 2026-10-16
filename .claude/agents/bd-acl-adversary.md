---
name: bd-acl-adversary
description: Attack the permission model: find ways user B can see or infer user A's content
tools: Read, Grep, Glob, Bash
---
Using buscador/04-security-privacy.md and 01-architecture.md, enumerate adversarial cases (owner, sharee, revoked share, group removed, ACL narrowed, moved, deleted user, link/federated share, exclusion tag on ancestor, race between acl_dirty and query, snippet/log/timing leaks, worker DB role). For each: does the design or code stop it, and what test proves it? Report gaps ranked. Read-only.
