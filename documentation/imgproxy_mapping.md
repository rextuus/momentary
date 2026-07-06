# Imgproxy URL Quellen

Folgende Entitäten nutzen nun den `ImgproxyService` indirekt über ihre Getter, wobei die URLs zur Laufzeit generiert werden sollten (z. B. via Serializer-Subscriber oder Twig). Hier ist die Zuordnung der Quell-Pfade:

| Entity      | Getter                 | Quell-Property (Pfad)        | Beschreibung                                      |
|-------------|------------------------|------------------------------|--------------------------------------------------|
| `Person`    | `getProfileImageUrl()` | `profileFace->faceImagePath` | Das Pfad-Feld des verknüpften Profil-Gesichts.    |
| `Video`     | `getThumbnailUrl()`    | `convertedVideoPath`         | Der Pfad zum konvertierten Video (als Thumbnail). |
| `VideoFace` | `getImageUrl()`        | `faceImagePath`              | Der direkte Pfad zum ausgeschnittenen Gesicht.   |

**Wichtig:** Diese Getter sind als Read-Only konzipiert und besitzen keine korrespondierenden Datenbank-Spalten (`#[ORM\Column]`).
