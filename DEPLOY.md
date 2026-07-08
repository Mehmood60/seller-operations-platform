# Deploying free on an Oracle Cloud "Always Free" VM

This guide takes the app live for **$0/month** on a real Linux VM with a persistent
disk and free HTTPS — no software needs to be installed on your work laptop
(Windows 11 already includes the `ssh` command you need).

The stack runs from [`docker-compose.prod.yml`](docker-compose.prod.yml):
Caddy (HTTPS) → Next.js frontend + PHP backend, with data on a persistent volume.

---

## Overview of what you'll do

1. Create a free Oracle Cloud VM (ARM, persistent disk).
2. Point a free domain (DuckDNS) at the VM's IP.
3. Register a **Production** eBay keyset + redirect URL.
4. Copy the code to the VM, fill in secrets, `docker compose up`.
5. Connect eBay and go live.

Budget ~45–60 minutes the first time.

---

## Step 1 — Create the free VM

1. Sign up at <https://cloud.oracle.com> (credit card is for identity verification
   only — "Always Free" resources are never charged).
2. **Compute → Instances → Create instance.**
   - Image: **Ubuntu 22.04**.
   - Shape: **Ampere (ARM) VM.Standard.A1.Flex** — set 1–2 OCPU / 6–12 GB RAM
     (all within the Always Free allowance). If ARM capacity is unavailable in
     your region, use **VM.Standard.E2.1.Micro** (x86, also always-free).
   - Under **Add SSH keys**, choose **Generate a key pair** and download the
     private key (e.g. `oracle_key.key`).
3. After it boots, copy the **Public IP address**.
4. **Networking → open ports 80 and 443:**
   - In the instance's **Virtual Cloud Network → Security List**, add two
     **Ingress rules**: Source `0.0.0.0/0`, TCP, destination ports `80` and `443`.

## Step 2 — Free domain (DuckDNS)

eBay Production OAuth requires an `https://` redirect, which needs a domain.

1. Go to <https://www.duckdns.org>, sign in (GitHub/Google).
2. Create a subdomain, e.g. `myseller` → gives you `myseller.duckdns.org`.
3. Set its IP to your VM's Public IP. Save.

## Step 3 — Connect to the VM (no install needed)

Open **PowerShell** on Windows and SSH in with the key you downloaded:

```powershell
# Lock down the key file's permissions (Windows requirement), then connect
icacls "$HOME\Downloads\oracle_key.key" /inheritance:r /grant:r "$($env:USERNAME):(R)"
ssh -i "$HOME\Downloads\oracle_key.key" ubuntu@YOUR_VM_PUBLIC_IP
```

(If you can't use a local key at all, Oracle's browser **Cloud Shell** also works.)

## Step 4 — Install Docker on the VM

You're root here, so this is allowed. Run on the VM:

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
newgrp docker            # apply the group without re-login
# Ubuntu firewall (in addition to Oracle's security list):
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80  -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

## Step 5 — Get the code onto the VM

Easiest is Git (push your repo to GitHub/GitLab first, then clone). On the VM:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone YOUR_REPO_URL seller-app
cd seller-app
```

> `backend/.env` and `backend/data/` are git-ignored (correctly), so they won't
> come across — you'll create the `.env` fresh in the next step.

## Step 6 — eBay Production credentials

1. At <https://developer.ebay.com> → **My Account → Application Keys**, switch to
   the **Production** keyset (create one if needed). Note the **App ID (Client ID)**,
   **Cert ID (Client Secret)**, and **Dev ID**.
2. **User Tokens → Get a Token from eBay via Your Application → Add eBay Redirect URL**
   (this creates a **RuName**). Set:
   - **Your auth accepted URL:** `https://myseller.duckdns.org/api/auth/ebay/callback`
   - **Your privacy policy URL:** any page you control (a simple text page is fine).
   - eBay generates a **RuName** string — copy it.

## Step 7 — Configure secrets on the VM

Create the two config files. First the root `.env` used by compose:

```bash
# in the seller-app directory
cat > .env <<'EOF'
DOMAIN=myseller.duckdns.org
API_KEY=PASTE_A_STRONG_SECRET_HERE
EOF
```

Then the backend env (copy the example and edit it):

```bash
cp backend/.env.example backend/.env
nano backend/.env
```

Set at least these values for production:

```ini
APP_ENV=production
APP_URL=https://myseller.duckdns.org
FRONTEND_URL=https://myseller.duckdns.org
STORAGE_DRIVER=json

# MUST match the API_KEY in the root .env above
API_KEY=PASTE_A_STRONG_SECRET_HERE

EBAY_CLIENT_ID=your-production-app-id
EBAY_CLIENT_SECRET=your-production-cert-id
EBAY_DEV_ID=your-dev-id
EBAY_REDIRECT_URI=your-RuName-from-step-6   # the RuName, NOT the URL
EBAY_SITE_ID=3                              # 3=UK, 0=US, 77=DE ...

EBAY_SANDBOX=false                          # <-- flip to production

# Exactly 32 characters (generate: openssl rand -hex 16)
ENCRYPTION_KEY=your-32-char-key

# Free AI key from https://console.groq.com
GROQ_API_KEY=gsk_your-key
```

Generate strong values right on the VM:

```bash
openssl rand -hex 32   # -> API_KEY (use the SAME value in both files)
openssl rand -hex 16   # -> ENCRYPTION_KEY (32 chars)
```

## Step 8 — Launch

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f
```

Caddy will fetch a TLS certificate automatically (give it ~30 s on first run).
Then open **https://myseller.duckdns.org** — the app is live.

## Step 9 — Connect eBay & verify

1. Go to **Settings** in the app → **Connect eBay** → authorize your real account.
2. Trigger a sync; confirm listings/orders load.
3. Data persists across restarts (stored on the `seller_data` volume).

---

## Everyday operations

```bash
# Update after pushing new code
git pull && docker compose -f docker-compose.prod.yml up -d --build

# View logs / restart / stop
docker compose -f docker-compose.prod.yml logs -f
docker compose -f docker-compose.prod.yml restart
docker compose -f docker-compose.prod.yml down        # stops; keeps data

# Back up the JSON data volume
docker run --rm -v seller-app_seller_data:/data -v $PWD:/backup alpine \
  tar czf /backup/data-backup.tgz -C /data .
```

## Notes & gotchas

- **`NEXT_PUBLIC_*` are baked at build time.** If you change `DOMAIN`, you must
  rebuild the frontend (`up -d --build`), not just restart it.
- The PHP backend uses the built-in `php -S` server. Fine for a single-seller /
  low-traffic app; not intended for high concurrency.
- **Never commit `backend/.env` or the root `.env`** — both are git-ignored already.
- DuckDNS IPs can go stale if the VM IP changes; reserve a static public IP in
  Oracle (also free) to avoid this.
