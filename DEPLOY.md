# Deployment Playbook — Seller Operations Platform

This is the real, working deployment: a free **Oracle Cloud "Always Free" VM** running
the app in Docker (PHP backend + Next.js frontend + Caddy for HTTPS), with JSON file
storage on a persistent volume.

> ⚠️ **Never put secrets in this file.** API keys, eBay credentials, and encryption keys
> live only in `backend/.env` and the root `.env` **on the server** (both git-ignored).
> This file is committed to GitHub, so it must stay secret-free.

---

## 📌 Your deployment at a glance

| Thing | Value |
|---|---|
| Cloud | Oracle Cloud Free Tier (region: **eu-frankfurt-1**, AD-2) |
| VM shape | **VM.Standard.E2.1.Micro** (x86, 1 OCPU, 1 GB RAM — Always Free) |
| OS | Ubuntu 22.04 |
| Public IP | **89.168.112.111** *(ephemeral — can change if the VM is stopped/started; see Troubleshooting)* |
| Domain | **sellarapp.duckdns.org** (DuckDNS, free) |
| SSH login | `ubuntu@89.168.112.111` |
| SSH key (local) | `D:\personal_mehmood\Onlinestore app\ssh-key-2026-07-08.key` |
| App folder (server) | `~/seller-operations-platform` |
| Live URL | **https://sellarapp.duckdns.org** |

**Architecture (single domain):**
```
Internet ──443──> Caddy ──/api/*──> backend  (PHP, :8080)
                       └──else────> frontend (Next.js, :3000)
```

---

## 🔑 Connect to the server

From **PowerShell** on your Windows machine:

```powershell
ssh -i "D:\personal_mehmood\Onlinestore app\ssh-key-2026-07-08.key" ubuntu@89.168.112.111
```

If SSH complains *"UNPROTECTED PRIVATE KEY FILE" / "bad permissions"*, fix the key ACL once:
```powershell
$key = "D:\personal_mehmood\Onlinestore app\ssh-key-2026-07-08.key"
icacls $key /inheritance:r
icacls $key /remove:g "*S-1-5-11" "*S-1-5-32-545"
icacls $key /grant:r "$($env:USERNAME):(R)"
```

---

## 🚀 Updating production (the common case — you changed code)

This is what you'll do **every time you add a feature or fix a bug**:

1. **On your Windows machine** — commit and push your changes to GitHub:
   ```powershell
   cd "D:\personal_mehmood\Onlinestore app\seller-operations-platform"
   git add -A
   git commit -m "your change description"
   git push origin main
   ```

2. **On the server** — pull and rebuild:
   ```bash
   cd ~/seller-operations-platform
   git pull
   docker compose -f docker-compose.prod.yml up -d --build
   ```

Docker rebuilds only what changed and restarts the containers. Takes ~5–15 min
(the frontend build is the slow part on this small VM).

### Faster partial updates
- **Backend-only code change** → rebuild just the backend:
  ```bash
  docker compose -f docker-compose.prod.yml up -d --build backend
  ```
- **Changed a value in `backend/.env`** (eBay key, Groq key, etc.) → no rebuild needed,
  just restart the backend:
  ```bash
  docker compose -f docker-compose.prod.yml restart backend
  ```
- **Changed the domain, `API_KEY`, or anything `NEXT_PUBLIC_*`** → the frontend bakes these
  at build time, so you **must** rebuild the frontend:
  ```bash
  docker compose -f docker-compose.prod.yml up -d --build frontend
  ```

---

## 🛠️ Everyday operations

```bash
cd ~/seller-operations-platform

# See running containers + health
docker compose -f docker-compose.prod.yml ps

# Watch logs (all, or one service)
docker compose -f docker-compose.prod.yml logs -f
docker compose -f docker-compose.prod.yml logs -f backend
docker compose -f docker-compose.prod.yml logs -f caddy

# Restart everything / one service
docker compose -f docker-compose.prod.yml restart
docker compose -f docker-compose.prod.yml restart backend

# Stop (data on the volume is preserved)
docker compose -f docker-compose.prod.yml down

# Start again
docker compose -f docker-compose.prod.yml up -d
```

### Back up your data (JSON storage)
Your eBay tokens, listings, orders, etc. live in the `seller_data` Docker volume.
```bash
cd ~/seller-operations-platform
# Find the exact volume name if unsure:
docker volume ls | grep seller_data
# Back it up to a dated tarball in the current folder:
docker run --rm \
  -v seller-operations-platform_seller_data:/data \
  -v "$PWD":/backup alpine \
  tar czf /backup/data-backup-$(date +%F).tgz -C /data .
```
Copy the tarball to your laptop with `scp` (run on Windows):
```powershell
scp -i "D:\personal_mehmood\Onlinestore app\ssh-key-2026-07-08.key" ubuntu@89.168.112.111:/home/ubuntu/seller-operations-platform/data-backup-*.tgz .
```

---

## 🧯 Troubleshooting

### Site won't load / HTTPS certificate error
```bash
docker compose -f docker-compose.prod.yml logs caddy
```
- Confirm DNS points at the VM: `getent hosts sellarapp.duckdns.org` → should show the VM's IP.
- Confirm ports 80/443 are open at **both** layers:
  - Oracle: VCN → Security → Default Security List → Security rules (ingress 80, 443, 0.0.0.0/0).
  - Ubuntu: `sudo iptables -L INPUT -n --line-numbers` → ACCEPT 80 & 443 **above** the REJECT line.
- Let's Encrypt needs port **80** reachable to issue the certificate.

### Public IP changed (after stopping/starting the VM)
The IP is **ephemeral**. If it changes:
1. Get the new IP: Oracle console → Instance → Public IP.
2. Update DuckDNS: <https://www.duckdns.org> → set the new IP → update.
3. (Optional, recommended) Reserve the IP in Oracle so it never changes:
   Instance → Attached VNICs → VNIC → IPv4 Addresses → edit → **Reserved public IP**.

### Frontend build fails / runs out of memory
```bash
free -h    # confirm Swap shows 4.0Gi
```
If swap is missing (e.g. after a fresh VM), recreate it:
```bash
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### eBay login fails / redirects wrong
- The redirect URL registered for the RuName at **developer.ebay.com** (Production keyset →
  User Tokens) must be exactly: `https://sellarapp.duckdns.org/api/auth/ebay/callback`
- `backend/.env` must have `EBAY_SANDBOX=false`, `APP_URL` and `FRONTEND_URL` =
  `https://sellarapp.duckdns.org`, and the Production `EBAY_CLIENT_ID` / `EBAY_CLIENT_SECRET` /
  `EBAY_REDIRECT_URI` (RuName). After editing: `restart backend`.

### Check container health / a service keeps restarting
```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs --tail=100 backend
```

---

## 🌱 First-time server setup (reference — already done once)

Kept here so you can rebuild from scratch on a new VM if ever needed.

**1. Create the VM** — Oracle Cloud → Compute → Instance:
Ubuntu 22.04, shape **VM.Standard.E2.1.Micro** (Always Free; if you can get **Ampere A1.Flex**
with 6 GB it's better — pick the matching aarch64 image), public subnet, **generate + download SSH key**.
Assign an ephemeral public IP (Instance → VNIC → IPv4 Addresses → edit → Ephemeral).

**2. Open firewall** — Oracle Security List ingress TCP 80 & 443 from `0.0.0.0/0`, then on the VM:
```bash
sudo iptables -I INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

**3. Point the domain** — DuckDNS: set `sellarapp` → the VM's public IP.

**4. Add swap** (needed for the build on 1 GB RAM):
```bash
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

**5. Install Docker:**
```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
newgrp docker
```

**6. Clone the code:**
```bash
git clone https://github.com/Mehmood60/seller-operations-platform.git
cd seller-operations-platform
```

**7. Create the config** — copy your local `backend/.env` up with `scp` (from Windows):
```powershell
scp -i "D:\personal_mehmood\Onlinestore app\ssh-key-2026-07-08.key" "D:\personal_mehmood\Onlinestore app\seller-operations-platform\backend\.env" ubuntu@<VM_IP>:/home/ubuntu/seller-operations-platform/backend/.env
```
Then on the server, set the production values:
```bash
cd ~/seller-operations-platform/backend
sed -i 's|^APP_ENV=.*|APP_ENV=production|' .env
sed -i 's|^APP_URL=.*|APP_URL=https://sellarapp.duckdns.org|' .env
sed -i 's|^FRONTEND_URL=.*|FRONTEND_URL=https://sellarapp.duckdns.org|' .env
sed -i "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=$(openssl rand -hex 16)|" .env
# root .env for docker-compose (domain + matching API key):
cd ~/seller-operations-platform
echo "DOMAIN=sellarapp.duckdns.org" > .env
grep '^API_KEY=' backend/.env >> .env
```

**8. Register the eBay redirect** — developer.ebay.com → Production keyset → User Tokens →
auth accepted URL = `https://sellarapp.duckdns.org/api/auth/ebay/callback`.

**9. Deploy:**
```bash
cd ~/seller-operations-platform
docker compose -f docker-compose.prod.yml up -d --build
```

Then open **https://sellarapp.duckdns.org** and connect eBay from the Settings page.
