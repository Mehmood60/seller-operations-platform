# ── frontend/Dockerfile ──────────────────────────────────────────────────────
# Stages:
#   deps     — install node_modules (cache layer)
#   builder  — next build (standalone output)
#   runner   — minimal runtime image
# ─────────────────────────────────────────────────────────────────────────────

# ── deps ─────────────────────────────────────────────────────────────────────
FROM node:20-alpine AS deps

RUN apk add --no-cache libc6-compat

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev

# ── builder ──────────────────────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Copy all node_modules (including dev — needed for next build)
COPY package.json package-lock.json* ./
RUN npm ci

COPY . .

# Public env vars baked into the JS bundle at build time
ARG NEXT_PUBLIC_API_BASE_URL=http://localhost:8080
ARG NEXT_PUBLIC_API_KEY=dev-secret-key-change-me

ENV NEXT_PUBLIC_API_BASE_URL=$NEXT_PUBLIC_API_BASE_URL
ENV NEXT_PUBLIC_API_KEY=$NEXT_PUBLIC_API_KEY

# next.config.ts must have output: 'standalone'
RUN npm run build

# ── runner ───────────────────────────────────────────────────────────────────
FROM node:20-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production

# Non-root user for security
RUN addgroup --system --gid 1001 nodejs \
 && adduser  --system --uid 1001 nextjs

# Copy only what Next.js standalone output needs
COPY --from=builder /app/public           ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static    ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
