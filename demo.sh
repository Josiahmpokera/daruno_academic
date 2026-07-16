#!/usr/bin/env bash
# =============================================================================
# Shengena / Daruno — load demo data (schools, academic structure, students)
# =============================================================================
# Usage:
#   ./demo.sh              # migrate (if needed) + seed demo pack
#   ./demo.sh --fresh      # migrate:fresh + seed full demo (DESTROYS DATA)
#   ./demo.sh --students   # re-seed students only (idempotent top-up to 32/level)
#   ./demo.sh --help
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="${ROOT}/daruno_backend"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

log()  { echo -e "${CYAN}▶${NC} $*"; }
ok()   { echo -e "${GREEN}✓${NC} $*"; }
warn() { echo -e "${YELLOW}!${NC} $*"; }
err()  { echo -e "${RED}✗${NC} $*" >&2; }

usage() {
  cat <<'EOF'
Shengena demo data loader

  ./demo.sh              Migrate (safe) + seed demo pack
  ./demo.sh --fresh      migrate:fresh --seed  (wipes database)
  ./demo.sh --students   Only DemoStudentsSeeder (top-up to ≥30 per level)
  ./demo.sh --structure  Academic structure + students (no platform recreate)
  ./demo.sh --help       This help

Demo schools after seed:

  Code     School                      Stages                         Admin login
  -------  --------------------------  -----------------------------  ---------------------------
  GVS001   Green Valley Secondary      O-Level + A-Level              admin@gvs001.school
  SPA002   Sunrise Primary Academy     Pre-primary + Primary          admin@spa002.school
  LHS003   Lakeview Combined School    All four stages                admin@lhs003.school

  Password (all school admins):  School123!
  Platform owner:                owner@daruno.app / Daruno123!

Students: ≥32 per education level the school operates (education_stages).
List/headcount APIs only show levels/stages configured for that school.
EOF
}

MODE="default"
for arg in "$@"; do
  case "$arg" in
    --fresh) MODE="fresh" ;;
    --students) MODE="students" ;;
    --structure) MODE="structure" ;;
    -h|--help) usage; exit 0 ;;
    *) err "Unknown option: $arg"; usage; exit 1 ;;
  esac
done

if [[ ! -d "$BACKEND" ]]; then
  err "Backend not found at $BACKEND"
  exit 1
fi

cd "$BACKEND"

if [[ ! -f .env ]]; then
  warn ".env missing — copying .env.example"
  cp .env.example .env
  php artisan key:generate --force || true
fi

if ! command -v php >/dev/null 2>&1; then
  err "php is required"
  exit 1
fi

case "$MODE" in
  fresh)
    warn "This will WIPE the database and re-seed everything."
    read -r -p "Type YES to continue: " confirm
    if [[ "$confirm" != "YES" ]]; then
      err "Aborted."
      exit 1
    fi
    log "migrate:fresh --seed"
    php artisan migrate:fresh --seed --force
    ;;
  students)
    log "Seeding demo students only (DemoStudentsSeeder)…"
    php artisan db:seed --class=DemoStudentsSeeder --force
    ;;
  structure)
    log "Seeding academic structure + demo students…"
    php artisan db:seed --class=AcademicStructureSeeder --force
    php artisan db:seed --class=DemoStudentsSeeder --force
    ;;
  default)
    log "Running migrations…"
    php artisan migrate --force
    log "Seeding demo pack (DemoDataSeeder)…"
    php artisan db:seed --class=DemoDataSeeder --force
    ;;
esac

ok "Demo data ready."
echo ""
echo -e "${GREEN}Quick login${NC}"
echo "  Platform:  owner@daruno.app / Daruno123!"
echo "  Secondary: admin@gvs001.school / School123!   (O + A only → 64+ students)"
echo "  Primary:   admin@spa002.school / School123!   (Pre + Primary → 64+ students)"
echo "  Combined:  admin@lhs003.school / School123!   (all stages → 128+ students)"
echo ""
echo "  Re-run students only:  ./demo.sh --students"
echo ""
