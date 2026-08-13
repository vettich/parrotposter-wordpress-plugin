# Scratch WordPress for plugin upgrade tests

No bind-mount of the plugin git tree. Agent scripts live in `meta/local-dev/upgrade/`.

```bash
cp -n .env.example .env
# or: meta/local-dev/upgrade/up.sh
docker compose up -d
```

Port default: `10122` (see workspace `PORTS.md`).
