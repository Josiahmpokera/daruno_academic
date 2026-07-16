## Shengena Logo Meaning

Shengena = “One Classroom, One System”

Or in deeper Swahili sense:

“Mfumo mmoja unaounganisha darasa, walimu, wanafunzi na wazazi.”

This makes Shengena perfect for:

- Student records
- Attendance
- Exams & results
- Parent communication
- School administration

## Product modules

Full catalog (29 modules, AI excluded): see `../docs/MODULES.md`  
API scaffolding: see `../docs/API.md`  
Module registry config: `config/daruno_modules.php`

```bash
# Module catalog
GET /api/v1/modules

# Tenant-scoped resource (Bearer + X-School-ID)
GET /api/v1/tenant/students
GET /api/v1/tenant/finance/features/invoices
```
