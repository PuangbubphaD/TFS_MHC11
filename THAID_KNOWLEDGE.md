# องค์ความรู้ ThaiD Login — VMS

เอกสารนี้สรุปการ implement จริงในโปรเจกต์ **VMS_Antigravity** สำหรับ developer หรือ AI agent ที่ต้องทำงานต่อ  
เอกสาร setup ฝั่ง DOPA/`.env`: ดู [`THAID_SETUP.md`](THAID_SETUP.md)

---

## 1. ภาพรวม

ระบบใช้ **ThaiD (DOPA Digital ID)** แบบ **OAuth 2.0 Authorization Code + OpenID Connect**

| ชั้น | เทคโนโลยี |
|------|-----------|
| Backend | Laravel 12, session auth + Laravel Sanctum (SPA cookie) |
| Frontend | Angular standalone, `withCredentials: true` |
| JWT | `firebase/php-jwt` — decode/verify id_token ผ่าน JWKS |
| บทบาท | Spatie Permission (`admin`, `user`, `driver`, …) |

**Login ThaiD ไม่ใช่ API token แยก** — หลัง callback สำเร็จใช้ `Auth::login()` + session cookie เหมือน login อีเมล/รหัสผ่าน

---

## 2. สถาปัตยกรรม

```
Angular :4200                          Laravel :8000
    │                                       │
    │  GET /auth/thaid/redirect             │  web route (session)
    │  (window.location — ไม่ผ่าน /api)     │
    ├──────────────────────────────────────►│  เก็บ state ใน session
    │                                       │  redirect → DOPA authorize
    │                                       │
    │◄──────────────────────────────────────┤  GET /auth/thaid/callback?code&state
    │  redirect /login?thaid=success        │  แลก token → จับคู่ user → Auth::login
    │                                       │
    │  GET /api/user (cookie session)        │
    ├──────────────────────────────────────►│
```

- **OAuth redirect/callback** → `backend/routes/web.php` (ต้องเป็น session เดียวกับ Sanctum)
- **Status / register / confirm** → `backend/routes/api.php` (ใช้ session cookie + CSRF)

Frontend เรียก API ที่ `{hostname}:8000/api` — ดู `frontend/src/app/shared/api-origin.ts`

---

## 3. DOPA Endpoints

| รายการ | URL |
|--------|-----|
| Discovery (prod) | `https://imauth.bora.dopa.go.th/.well-known/openid-configuration` |
| Discovery (sandbox) | `https://imauthsbx.bora.dopa.go.th/.well-known/openid-configuration` |
| Authorize | `https://imauth.bora.dopa.go.th/api/v2/oauth2/auth/` |
| Token | จาก discovery `token_endpoint` |
| Introspect | `https://imauth.bora.dopa.go.th/api/v2/oauth2/introspect/` |
| Userinfo | จาก discovery (fallback) |

### จุดสำคัญ (ThaiD ไม่เหมือน OIDC ทั่วไป)

เมื่อ scope เป็น `pid title given_name family_name` DOPA มักส่ง **`pid`, `given_name`, `family_name` ใน token response โดยตรง** — ไม่ใช่แค่ใน id_token

ลำดับอ่าน profile ใน `ThaiDAuthService::resolveUserInfo()`:

1. ฟิลด์ใน token response โดยตรง
2. decode JWT (`id_token` / `access_token`) + JWKS
3. introspect
4. userinfo (fallback)

---

## 4. Environment (.env)

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:4200

THAID_ENABLED=true
THAID_CLIENT_ID=...
THAID_CLIENT_SECRET=...
THAID_REDIRECT_URI=http://localhost:8000/auth/thaid/callback
THAID_SCOPE="openid pid title given_name family_name"

# ทางเลือก
THAID_USE_SANDBOX=false
THAID_API_KEY=              # ถ้า pid เข้ารหัส — decrypt AES
THAID_LINK_BY_EMAIL=true
THAID_LINK_BY_NAME=true
THAID_SYNC_PROFILE=true
THAID_CONFIRM_NAME_OVERWRITE=true
THAID_AUTO_REGISTER=true
THAID_REQUIRE_ADMIN_APPROVAL=true

SANCTUM_STATEFUL_DOMAINS=localhost:4200,127.0.0.1:4200,192.168.x.x:4200
```

**Callback URL ต้องตรง RP Admin เป๊ะ** — ผิดแล้วได้ `Bad data request`

Config รวม: `backend/config/thaid.php`

---

## 5. Database (`users`)

Migration: `backend/database/migrations/2026_05_26_000001_add_thaid_fields_to_users_table.php`

| คอลัมน์ | ความหมาย |
|---------|----------|
| `thaid_sub` | OIDC subject (unique, nullable) |
| `thaid_pid` | เลขบัตร 13 หลัก (unique, nullable) |
| `auth_provider` | `local` / `thaid` |
| `thaid_linked_at` | วันที่ผูก ThaiD |
| `account_status` | `active` / `pending_approval` / `rejected` / `suspended` |

Migration สถานะบัญชี: `backend/database/migrations/2026_05_29_000001_add_account_status_to_users_table.php`

---

## 6. Flow หลัก (Login)

```
1. User คลิก «เข้าสู่ระบบด้วย ThaiD»
2. GET /auth/thaid/redirect?returnUrl=/dashboard (optional)
   → session: thaid_oauth_state, thaid_return_url
3. Redirect DOPA authorize (state, client_id, redirect_uri, scope)
4. DOPA → GET /auth/thaid/callback?code=...&state=...
5. ตรวจ state (hash_equals)
6. exchangeAuthorizationCode(code) → tokens
7. resolveUserInfo(tokens) → { sub, pid, given_name, family_name, ... }
8. resolveUser(userInfo) — ลำดับจับคู่:
   a. thaid_sub
   b. thaid_pid
   c. email (ถ้า THAID_LINK_BY_EMAIL)
   d. ชื่อ+นามสกุล (ถ้า THAID_LINK_BY_NAME และตรง 1 คนเท่านั้น)
      → มากกว่า 1 คน = throw thaid_name_ambiguous
9. ไม่พบ user:
   → THAID_AUTO_REGISTER=true → session thaid_register_profile → /register/thaid
   → false → error no_account
10. พบ user แต่ account_status ≠ active → error (pending_approval / rejected / suspended)
11. getNameOverwriteProposal() ไม่ null:
    → session thaid_profile_confirm → /auth/thaid/confirm-profile
12. syncThaiDProfile() (เติมช่องว่าง ไม่ทับ)
13. Auth::login + session regenerate
14. Redirect FRONTEND /login?thaid=success&returnUrl=...
15. Angular เรียก GET /api/user → เข้า dashboard
```

---

## 7. Flow สมัครใหม่ (`/register/thaid`)

เกิดเมื่อ callback ไม่พบ user + `THAID_AUTO_REGISTER=true`

Session key: `thaid_register_profile` (หมดอายุ 30 นาที)

| API | Method | หน้าที่ |
|-----|--------|--------|
| `/api/auth/thaid/register/prefill` | GET | ชื่อ/pid_masked จาก session |
| `/api/auth/thaid/register` | POST | สร้าง user |

Body สมัคร:

```json
{
  "email": "required|unique",
  "password": "min:6|confirmed",
  "position": "optional"
}
```

- ชื่อ/นามสกุล/pid มาจาก ThaiD (ไม่ให้ user แก้)
- `account_status` = `pending_approval` ถ้า `THAID_REQUIRE_ADMIN_APPROVAL=true`
- assign role `user`
- Admin สร้าง user เอง → `active` ทันที (ไม่รออนุมัติ)

Controller: `backend/app/Http/Controllers/ThaiDRegistrationController.php`  
UI: `frontend/src/app/features/auth/register-thaid/`

---

## 8. Flow ยืนยันชื่อ (`/auth/thaid/confirm-profile`)

เกิดเมื่อ:

- `thaid_pid` ใน DB **ตรง** pid จาก ThaiD
- **และ** `name` หรือ `lastname` มีค่าแล้ว แต่ normalize แล้ว**ไม่ตรง** ThaiD
- `THAID_CONFIRM_NAME_OVERWRITE=true`

Session key: `thaid_profile_confirm` (30 นาที)

| API | ผลลัพธ์ |
|-----|---------|
| `GET /api/auth/thaid/profile-update/preview` | current vs proposed |
| `POST /api/auth/thaid/profile-update/accept` | ทับชื่อจาก ThaiD → login |
| `POST /api/auth/thaid/profile-update/decline` | ใช้ชื่อเดิม → sync อื่นๆ → login |

Controller: `backend/app/Http/Controllers/ThaiDProfileConfirmController.php`  
UI: `frontend/src/app/features/auth/confirm-thaid-profile/`

**ช่องว่าง** (ไม่มีชื่อ/สกุล) → `syncThaiDProfile()` เติมอัตโนมัติ ไม่แสดงหน้านี้

---

## 9. Routes

### Web (`backend/routes/web.php`)

```
GET /auth/thaid/redirect   → ThaiDAuthController@redirect
GET /auth/thaid/callback   → ThaiDAuthController@callback
```

### API (`backend/routes/api.php`)

```
GET  /api/auth/thaid/status
GET  /api/auth/thaid/register/prefill
POST /api/auth/thaid/register
GET  /api/auth/thaid/profile-update/preview
POST /api/auth/thaid/profile-update/accept
POST /api/auth/thaid/profile-update/decline
```

### Frontend (`frontend/src/app/app.routes.ts`)

```
/register/thaid
/auth/thaid/confirm-profile
/login  (รับ query thaid=success|error)
```

---

## 10. ไฟล์หลัก

| หน้าที่ | Path |
|--------|------|
| Config | `backend/config/thaid.php` |
| Core logic | `backend/app/Services/ThaiDAuthService.php` |
| OAuth redirect/callback | `backend/app/Http/Controllers/ThaiDAuthController.php` |
| สมัคร | `backend/app/Http/Controllers/ThaiDRegistrationController.php` |
| ยืนยันชื่อ | `backend/app/Http/Controllers/ThaiDProfileConfirmController.php` |
| Login อีเมล + block inactive | `backend/app/Http/Controllers/AuthController.php` |
| Admin อนุมัตi | `backend/app/Http/Controllers/UserController.php` |
| User model | `backend/app/Models/User.php` |
| Account status | `backend/app/Support/AccountStatus.php` |
| Login UI | `frontend/src/app/features/auth/login/` |
| Register ThaiD | `frontend/src/app/features/auth/register-thaid/` |
| Confirm profile | `frontend/src/app/features/auth/confirm-thaid-profile/` |
| API client | `frontend/src/app/shared/api.service.ts` |
| API origin | `frontend/src/app/shared/api-origin.ts` |
| เอกสาร setup | `THAID_SETUP.md` |

---

## 11. Error codes → ข้อความ UI

Backend redirect: `/login?thaid=error&reason=...`  
Frontend map ใน `login.ts` → `mapThaiDError()`

| reason | ความหมาย |
|--------|----------|
| `no_account` | ไม่พบ user + auto_register ปิด |
| `denied` | user ยกเลิกที่แอป ThaiD |
| `disabled` | THAID_ENABLED=false หรือไม่มี client id/secret |
| `state_mismatch` | CSRF state ไม่ตรง |
| `name_ambiguous` | ชื่อ+นามสกุลตรงหลายบัญชี |
| `bad_request` | Callback URL / scope ไม่ตรง RP Admin |
| `callback_failed` | token/profile ล้มเหลว |
| `token_failed` | ไม่มี access_token/id_token |
| `pending_approval` | บัญชีรอ admin |
| `account_rejected` | ถูกปฏิเสธ |
| `account_suspended` | ถูกระงับ |

---

## 12. Session keys

| Key | ใช้เมื่อ |
|-----|---------|
| `thaid_oauth_state` | OAuth CSRF (pull หลัง callback) |
| `thaid_return_url` | path กลับหลัง login (ต้องขึ้นต้นด้วย `/`) |
| `thaid_register_profile` | สมัครใหม่ `{ profile, expires_at }` |
| `thaid_profile_confirm` | ยืนยันชื่อ `{ user_id, profile, return_url, expires_at }` |

---

## 13. กฎ sync profile

เมthod: `ThaiDAuthService::syncThaiDProfile()`

- เปิดเมื่อ `THAID_SYNC_PROFILE=true`
- **เติมเฉพาะช่องว่าง** — ไม่ทับ name/lastname ที่มีอยู่ (ยกเว้น user กด accept ในหน้า confirm)
- เติม `thaid_sub`, `thaid_pid` ถ้าว่าง
- pid ไม่ตรงที่มีอยู่ → log warning ไม่อัปเดต
- ตั้ง `auth_provider=thaid`, `thaid_linked_at` เมื่อผูกสำเร็จ

---

## 14. Sanctum / CSRF

- ก่อน POST register/accept/decline → `GET /sanctum/csrf-cookie`
- API ใช้ `withCredentials: true`
- เปิดจาก LAN → ตั้ง `SANCTUM_STATEFUL_DOMAINS` ให้รวม IP:4200

---

## 15. Admin workflow

- รายการ user: `/admin/users` — filter `pending_approval`
- API อนุมัตi/ปฏิเสธ: `UserController` (`approve` / `reject`)
- ผู้ใช้เก่าที่ยังไม่มี `thaid_pid` → admin ใส่เลขบัตรใน user หรือให้ login ThaiD ครั้งแรกเพื่อ auto-link

---

## 16. Troubleshooting

| อาการ | แก้ |
|-------|-----|
| `Bad data request` | Callback URL ใน RP Admin ≠ `THAID_REDIRECT_URI` |
| ปุ่ม ThaiD ไม่ขึ้น | `GET /api/auth/thaid/status` → `enabled: false` |
| Login success แต่ session ไม่ติด | Sanctum stateful domains / cookie / CORS |
| อ่าน pid ไม่ได้ | ตั้ง `THAID_API_KEY` ถ้า pid เข้ารหัส |
| หลัง deploy | `composer install` (firebase/php-jwt), restart backend |

---

## 17. สิ่งที่ต้องระวังก่อนแก้โค้ด

1. **อย่า** ย้าย callback ไป `/api` — ต้องเป็น web route + session
2. **อย่า** ทับชื่อโดยอัตโนมัติเมื่อ pid ตรง — ต้องผ่าน confirm (ถ้า config เปิด)
3. **อย่า** link by name เมื่อ match มากกว่า 1 คน
4. Login ทุกช่องทางต้องเช็ค `account_status === active`
5. ThaiD login สำเร็จแล้ว redirect ไป **frontend** `/login?thaid=success` ไม่ใช่ dashboard โดยตรง — FE เป็นคนเรียก `/api/user`

---

## 18. Prompt สั้นๆ สำหรับ AI agent

```
โปรเจกต์ VMS (Laravel 12 + Angular) มี ThaiD OAuth login แล้ว
อ่าน THAID_SETUP.md, THAID_KNOWLEDGE.md และไฟล์:
- backend/app/Services/ThaiDAuthService.php
- backend/app/Http/Controllers/ThaiDAuthController.php
- ThaiDRegistrationController, ThaiDProfileConfirmController
- frontend login/register-thaid/confirm-thaid-profile

Auth: Laravel session + Sanctum SPA cookie
OAuth: web routes /auth/thaid/redirect + /callback
DOPA ส่ง pid/ชื่อใน token response โดยตรง (ไม่ใช่แค่ id_token)
มี flow: login, auto-register (pending_approval), confirm name overwrite
Config: backend/config/thaid.php + .env THAID_*
```

---

## 19. Dependencies

```json
"firebase/php-jwt": "6.11"
```

ติดตั้งผ่าน `backend/composer.json` — ใช้ decode JWT + JWKS จาก DOPA
