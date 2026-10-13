# Diagrama entidad-relación (tablas propias del CMS)

Generado a partir de `database/migrations` y las relaciones de `app/Models`.
No incluye las tablas de SteVe ni las internas de Laravel (`cache`, `jobs`, `sessions`,
`personal_access_tokens`, `activity_log`) ni las de permisos de Spatie, salvo `roles`.

- Línea continua (`--`): FK real definida en la migración.
- Línea punteada (`..`): relación lógica, sin FK en la base de datos.

```mermaid
erDiagram
  companies ||--o{ locations : tiene
  companies ||--o{ users : agrupa
  companies ||--o{ rfid_tags : posee
  locations ||--o{ stations : contiene
  tariffs ||--o{ stations : aplica
  stations ||--o{ connectors : tiene
  stations ||--o{ charging_sessions : registra
  connectors ||--o{ charging_sessions : usa
  users ||--o{ charging_sessions : realiza
  rfid_tags ||--o{ charging_sessions : autoriza
  tariffs ||--o{ charging_sessions : "tariff y applied_tariff"
  vehicles ||--o{ charging_sessions : carga
  products ||--o{ charging_sessions : factura
  users ||--o| wallets : tiene
  wallets ||--o{ wallet_transactions : mueve
  users ||--o{ wallet_transactions : realiza
  charging_sessions ||..o{ wallet_transactions : "reference_id"
  wallet_transactions ||..o{ libelula_api_logs : "transaction_id"
  users ||--o{ rfid_tags : posee
  products ||--o{ rfid_tags : clasifica
  products ||--o{ tariffs : "energia, conexion, tiempo"
  products ||--o{ system_settings : "recarga, energia, conexion, penalidad"
  users ||--o{ vehicles : posee
  users ||--o{ promotion_views : ve
  promotions ||--o{ promotion_views : vista
  charging_sessions ||..o{ charging_session_notifications : notifica
  users ||..o{ charging_session_notifications : recibe
  remote_operators ||..o{ remote_audit_logs : "operator_id"
  stations ||..o{ remote_audit_logs : "charge_box_id"
  users }o--o{ roles : "model_has_roles"

  companies {
    bigint id PK
    string name
    string tax_id
  }
  locations {
    bigint id PK
    bigint company_id FK
    string google_maps_url
  }
  stations {
    bigint id PK
    string charge_box_id UK
    bigint location_id FK
    bigint tariff_id FK
  }
  connectors {
    bigint id PK
    bigint station_id FK
    int connector_id
  }
  tariffs {
    bigint id PK
    bigint energy_product_id FK
    bigint connection_product_id FK
    bigint time_product_id FK
  }
  products {
    bigint id PK
    string internal_code UK
    string siat_product_code
  }
  users {
    bigint id PK
    bigint company_id FK
    string sap_client_code
    string apple_sub
  }
  wallets {
    bigint id PK
    bigint user_id FK
  }
  wallet_transactions {
    bigint id PK
    bigint wallet_id FK
    bigint user_id FK
    string reference_id
    string external_payment_id
  }
  rfid_tags {
    bigint id PK
    string tag_code UK
    bigint user_id FK
    bigint company_id FK
    bigint product_id FK
    bool is_virtual
  }
  charging_sessions {
    bigint id PK
    string transaction_id UK
    bigint station_id FK
    bigint connector_id FK
    bigint user_id FK
    bigint rfid_tag_id FK
    bigint tariff_id FK
    bigint applied_tariff_id FK
    bigint vehicle_id FK
    bigint product_id FK
  }
  vehicles {
    bigint id PK
    bigint user_id FK
  }
  promotions {
    bigint id PK
  }
  promotion_views {
    bigint id PK
    bigint user_id FK
    bigint promotion_id FK
  }
  charging_session_notifications {
    bigint id PK
    bigint charging_session_id
    bigint user_id
  }
  libelula_api_logs {
    bigint id PK
    bigint transaction_id
  }
  system_settings {
    bigint id PK
    bigint product_recharge_id FK
    bigint product_energy_id FK
    bigint product_connection_id FK
    bigint product_penalty_id FK
  }
  remote_operators {
    bigint id PK
    string username
  }
  remote_audit_logs {
    bigint id PK
    bigint operator_id
    string charge_box_id
  }
  roles {
    bigint id PK
    string name
  }
```

## Vínculos con SteVe

| Columna del CMS | Equivale en SteVe |
|---|---|
| `stations.charge_box_id` | ID del cargador (`charge_box`) |
| `charging_sessions.transaction_id` | ID de la transacción (`transaction_pk`); el modelo carga las lecturas del medidor por esta columna |
| `rfid_tags.tag_code` | Tarjeta OCPP (`ocpp_tag`) |

## Puntos a revisar

- `wallet_transactions.reference_id` es un string que apunta a `charging_sessions.transaction_id`,
  pero también guarda la referencia de Libélula: una misma columna con dos significados.
- `charging_session_notifications`, `libelula_api_logs` y `remote_audit_logs` guardan IDs sin FK;
  si se borra el registro al que apuntan, quedan huérfanos.
- `wallet_transactions.user_id` aparece en tres migraciones con reglas distintas al borrar el usuario
  (`2026_02_09` y `2026_03_31` con `cascade`, `2026_03_30` con `nullOnDelete`); conviene confirmar
  qué regla quedó en producción.
