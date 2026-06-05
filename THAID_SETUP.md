# การตั้งค่า Login ด้วย ThaiD (VMS)

> องค์ความรู้ architecture / flow / ไฟล์ / troubleshooting สำหรับ developer และ AI: [`THAID_KNOWLEDGE.md`](THAID_KNOWLEDGE.md)

## 1. ขอสิทธิ์ใช้งาน ThaiD

1. หน่วยงานทำหนังสือขออนุญาตใช้ DOPA Digital ID (ThaiD) ตามหลักเกณฑ์ สป.  
   อ้างอิง: [digitalid.bora.dopa.go.th](https://digitalid.bora.dopa.go.th)
2. ลงทะเบียนแอปพลิเคชัน รับ **Client ID** และ **Client Secret**
3. ตั้ง **Callback URL** ให้ตรงกับ backend:

```
https://<your-api-host>/auth/thaid/callback
```

ตัวอย่าง dev:

```
http://localhost:8000/auth/thaid/callback
```

## 2. ตั้งค่า Backend (.env)

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:4200

THAID_ENABLED=true
THAID_CLIENT_ID=your_client_id
THAID_CLIENT_SECRET=your_client_secret
THAID_REDIRECT_URI=http://localhost:8000/auth/thaid/callback
THAID_SCOPE="openid pid title given_name family_name"
THAID_LINK_BY_NAME=true
THAID_SYNC_PROFILE=true
THAID_AUTO_REGISTER=true
THAID_REQUIRE_ADMIN_APPROVAL=true
THAID_CONFIRM_NAME_OVERWRITE=true
```

## 2.1 เปรียบเทียบกับตัวอย่าง PHP (XAMPP)

| รายการ | ตัวอย่าง PHP ที่ใช้ได้ | VMS (Laravel) |
|--------|------------------------|---------------|
| Authorize URL | `https://imauth.bora.dopa.go.th/api/v2/oauth2/auth/` | เหมือนกัน |
| Scope | `pid title given_name family_name` (หรือเพิ่ม `openid` ด้านหน้า) | `openid pid title given_name family_name` |
| Callback | `http://localhost/thaid_mhc11/thaid_callback.php` | `http://localhost:8000/auth/thaid/callback` |

**สาเหตุ `Bad data request`:** Callback URL ที่ส่งไป **ต้องลงทะเบียนใน RP Admin เป๊ะๆ**  
ถ้าใน portal ลงทะเบียนแค่ URL ของ XAMPP ต้อง **เพิ่ม** URL ของ VMS ด้วย:

```
http://localhost:8000/auth/thaid/callback
```

(DOPA อนุญาตหลาย callback ได้ — ถาม admin portal ถ้าเพิ่มไม่ได้)

รัน migration:

```bash
cd backend
php artisan migrate
```

## 3. การจับคู่และ sync ข้อมูล

เมื่อ login ด้วย ThaiD สำเร็จ ระบบจะหา user ตามลำดับ:

1. `thaid_sub` (จาก ThaiD ครั้งก่อน)
2. `thaid_pid` (เลขบัตร 13 หลัก)
3. อีเมลตรงกัน (ถ้า `THAID_LINK_BY_EMAIL=true`)
4. **ชื่อ + นามสกุล** (ถ้า `THAID_LINK_BY_NAME=true` และมีผู้ใช้ตรงกัน **เพียง 1 คน**)

**เมื่อจับคู่ได้** (`THAID_SYNC_PROFILE=true`):

| ใน VMS | จาก ThaiD | การทำ |
|--------|-----------|--------|
| มีชื่อ ไม่มีเลขบัตร | มี `pid` | เติม `thaid_pid` |
| มีเลขบัตร ไม่มีชื่อ/นามสกุล | มี `given_name`, `family_name` | เติม `name`, `lastname` (ไม่ต้องยืนยัน) |
| มี pid ตรง แต่ชื่อ/สกุลมีค่าแล้วไม่ตรง | ชื่อจาก ThaiD | redirect `/auth/thaid/confirm-profile` — user เลือก **ยอมรับจาก ThaiD** หรือ **ใช้ชื่อเดิม** |

**ยืนยันอัปเดตชื่อ** (`THAID_CONFIRM_NAME_OVERWRITE=true`):

- เกิดเมื่อ `thaid_pid` ใน VMS ตรงกับ `pid` จาก ThaiD **และ** ชื่อหรือนามสกุลในระบบมีค่าแล้วแต่ normalize แล้วไม่ตรง
- **ยอมรับ** → ทับ `name` / `lastname` จาก ThaiD แล้ว login
- **ใช้ชื่อเดิม** → login โดยไม่ทับชื่อ (sync ฟิลด์อื่นตามปกติ)
- ช่องว่าง (ไม่มีชื่อ/สกุล) → เติมอัตโนมัติ ไม่แสดงหน้ายืนยัน

**เมื่อไม่พบ user** (`THAID_AUTO_REGISTER=true`):

→ redirect ไป `/register/thaid` กรอก **อีเมล + รหัสผ่าน** (บังคับ)  
→ สถานะ `pending_approval` (ถ้า `THAID_REQUIRE_ADMIN_APPROVAL=true`)  
→ Admin อนุมัติที่ `/admin/users` → ปุ่ม «รออนุมัติ»

**บัญชีที่ admin สร้างเอง** → `active` ทันที (ไม่รออนุมัติ)

## 3.1 สถานะบัญชี (`account_status`)

| ค่า | ความหมาย |
|-----|----------|
| `active` | ใช้งานได้ |
| `pending_approval` | สมัครใหม่ รอ admin |
| `rejected` | admin ปฏิเสธ |
| `suspended` | ระงับ |

## 4. Flow ทางเทคนิค

```
Angular /login → คลิก «เข้าสู่ระบบด้วย ThaiD»
  → GET /auth/thaid/redirect (Laravel session)
  → DOPA authorize (แอป ThaiD)
  → GET /auth/thaid/callback?code=...
  → แลก token → อ่าน pid/ชื่อจาก token response
  → จับคู่ user → ตรวจ account_status
  → ถ้า pid ตรงแต่ชื่อไม่ตรง → /auth/thaid/confirm-profile (ยืนยันก่อนทับ)
  → มิฉะนั้น sync ข้อมูล → Auth::login
  → หรือ redirect /register/thaid (ไม่พบ user)
```

## 5. OIDC Endpoints (DOPA)

Discovery: `https://imauth.bora.dopa.go.th/.well-known/openid-configuration`

- Authorize: `/api/v2/oauth2/auth/`
- Token: `/api/v2/oauth2/token/`
- Userinfo: `/api/v2/oauth2/userinfo/` (VMS ใช้ **id_token** เป็นหลัก ตามตัวอย่าง ETDA/ThaiD-PHP-RP)

## 5.1 หมายเหตุสำคัญ (scope / id_token)

เมื่อ scope เป็น `pid title given_name family_name` DOPA จะส่ง **`pid`, `given_name`, `family_name` ใน token response โดยตรง** (ไม่ใช่ id_token / userinfo)  
ระบบ VMS อ่านจาก token response ก่อน แล้วค่อย fallback JWT / introspect / userinfo

แนะนำ scope: `openid pid title given_name family_name` (ต้องลงทะเบียน scope ใน RP Admin ให้ตรง)

หลังแก้ backend ให้ **รีสตาร์ท container** เพื่อ `composer install` package `firebase/php-jwt`:

```bash
docker compose restart backend
```

## 6. ไฟล์ที่เกี่ยวข้อง

| ส่วน | Path |
|------|------|
| Config | `backend/config/thaid.php` |
| Service | `backend/app/Services/ThaiDAuthService.php` |
| Controller | `backend/app/Http/Controllers/ThaiDAuthController.php` |
| Routes | `backend/routes/web.php`, `backend/routes/api.php` |
| Migration | `backend/database/migrations/2026_05_26_000001_add_thaid_fields_to_users_table.php` |
| Login UI | `frontend/src/app/features/auth/login/` |

## 7. ตรวจสอบ

- `GET /api/auth/thaid/status` → `{ "enabled": true }`
- ปุ่ม ThaiD แสดงบนหน้า login เมื่อ `THAID_ENABLED=true` และมี client id/secret
- Login ด้วยบัญชีที่มี `thaid_pid` ตรงกับ ThaiD แล้วเข้า dashboard ได้
