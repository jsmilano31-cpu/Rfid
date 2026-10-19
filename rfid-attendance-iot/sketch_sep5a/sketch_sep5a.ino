#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "secrets.h"

// =====================================================
// PIN CONFIGURATION
// =====================================================

// MFRC522
#define SS_PIN    5
#define RST_PIN   27
#define SCK_PIN   18
#define MISO_PIN  19
#define MOSI_PIN  23

// LCD
#define LCD_SDA   32
#define LCD_SCL   33

// Buzzer
#define BUZZER_PIN 25

// Status LEDs
#define LED_RED    13
#define LED_YELLOW 14
#define LED_GREEN  26

// =====================================================
// API CONFIGURATION
// =====================================================

const char *SCAN_ENDPOINT =
  "https://jsmkj.space/rfid-attendance-web/public/iot/scan.php";

const char *STATUS_ENDPOINT =
  "https://jsmkj.space/rfid-attendance-web/public/iot/status.php";

const unsigned long CARD_COOLDOWN_MS = 3000;
const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long STATUS_REFRESH_MS = 30000;
const unsigned long LCD_SCROLL_MS = 320;
const byte LCD_COLUMNS = 16;

// =====================================================
// HTTPS CERTIFICATE
// =====================================================

const char ISRG_ROOT_X1[] PROGMEM = R"CERT(
-----BEGIN CERTIFICATE-----
MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw
TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh
cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4
WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu
ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY
MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc
h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+
0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U
A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW
T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH
B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC
B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv
KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn
OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn
jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw
qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI
rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq
hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL
ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ
3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KKN
FtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5O
RAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur
TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC
jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc
oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq
4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA
mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d
emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=
-----END CERTIFICATE-----
)CERT";

// =====================================================
// COMPONENTS
// =====================================================

MFRC522 rfid(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);

// =====================================================
// STATE
// =====================================================

String lastUid;
String roomCode;
String currentCourseCode;
String currentComponentType;
String currentClassDay;
String currentClassStart;
String currentClassEnd;
String nextCourseCode;
String nextClassDay;
String nextClassStart;
String currentEventTitle;
String displayLine1;
String displayLine2;

unsigned long lastScanAt = 0;
unsigned long lastWifiAttempt = 0;
unsigned long lastStatusRefresh = 0;
unsigned long lastLcdScroll = 0;

size_t displayOffset1 = 0;
size_t displayOffset2 = 0;

bool scannerVerified = false;

// =====================================================
// FUNCTION DECLARATIONS
// =====================================================

void verifyScanner(bool announce = true);
void updateDisplay(bool force = false);
void waitWithDisplay(unsigned long duration);

// =====================================================
// LED STATUS HELPERS
// =====================================================

void setLeds(bool red, bool yellow, bool green) {
  digitalWrite(LED_RED, red ? HIGH : LOW);
  digitalWrite(LED_YELLOW, yellow ? HIGH : LOW);
  digitalWrite(LED_GREEN, green ? HIGH : LOW);
}

void statusNotReady() {
  setLeds(false, true, false);
}

void statusReady() {
  setLeds(false, false, true);
}

void statusWaiting() {
  setLeds(false, true, false);
}

void statusError() {
  setLeds(true, false, false);
}

void statusProcessing() {
  setLeds(false, true, false);
}

// =====================================================
// BUZZER
// =====================================================

void buzzerOn() {
  digitalWrite(BUZZER_PIN, HIGH);
}

void buzzerOff() {
  digitalWrite(BUZZER_PIN, LOW);
}

void beep(unsigned int duration) {
  buzzerOn();
  delay(duration);
  buzzerOff();
}

void beepCardDetected() {
  beep(80);
}

void beepSuccess() {
  beep(100);
  delay(100);
  beep(100);
}

void beepRegistration() {
  for (int i = 0; i < 3; i++) {
    beep(70);
    if (i < 2) delay(70);
  }
}

void beepScheduleRejected() {
  beep(220);
  delay(100);
  beep(220);
}

void beepError() {
  beep(600);
}

void beepHardwareError() {
  for (int i = 0; i < 3; i++) {
    beep(120);
    delay(120);
  }
}

void beepStartup() {
  beep(70);
  delay(80);
  beep(150);
}

// =====================================================
// LCD
// =====================================================

String scrollingWindow(const String &text, size_t offset) {
  if (text.length() <= LCD_COLUMNS) {
    String fixed = text;
    while (fixed.length() < LCD_COLUMNS) fixed += ' ';
    return fixed;
  }

  String loop = text + "   ";
  String window;
  window.reserve(LCD_COLUMNS);
  for (byte i = 0; i < LCD_COLUMNS; i++) {
    window += loop.charAt((offset + i) % loop.length());
  }
  return window;
}

void updateDisplay(bool force) {
  if (!force && millis() - lastLcdScroll < LCD_SCROLL_MS) return;
  lastLcdScroll = millis();

  lcd.setCursor(0, 0);
  lcd.print(scrollingWindow(displayLine1, displayOffset1));
  lcd.setCursor(0, 1);
  lcd.print(scrollingWindow(displayLine2, displayOffset2));

  if (displayLine1.length() > LCD_COLUMNS) displayOffset1 = (displayOffset1 + 1) % (displayLine1.length() + 3);
  if (displayLine2.length() > LCD_COLUMNS) displayOffset2 = (displayOffset2 + 1) % (displayLine2.length() + 3);
}

void showMessage(const String &line1, const String &line2) {
  displayLine1 = line1;
  displayLine2 = line2;
  displayOffset1 = 0;
  displayOffset2 = 0;
  updateDisplay(true);
}

void waitWithDisplay(unsigned long duration) {
  unsigned long started = millis();
  while (millis() - started < duration) {
    updateDisplay();
    delay(10);
  }
}

void showReady() {
  if (
    WiFi.status() == WL_CONNECTED &&
    scannerVerified
  ) {
    const String scannerName = roomCode.length() ? roomCode + " Scanner" : "Scanner ready";
    if (currentCourseCode.length()) {
      const String courseLabel = currentCourseCode + (currentComponentType.length() ? " " + currentComponentType : "");
      statusReady();
      showMessage(
        scannerName,
        "Current Class: " + courseLabel + " " + currentClassDay + " | " + currentClassStart + "-" + currentClassEnd + " | Tap to Scan"
      );
    } else if (currentEventTitle.length()) {
      statusReady();
      showMessage(scannerName, "Event: " + currentEventTitle + " | Tap to Scan");
    } else {
      statusWaiting();
      String schedule = "No current class";
      if (nextCourseCode.length()) {
        schedule += " | Next: " + nextCourseCode + " " + nextClassDay + " " + nextClassStart;
      }
      schedule += " | Tap to Scan";
      showMessage(scannerName, schedule);
    }
  } else {
    statusNotReady();

    showMessage(
      WiFi.status() == WL_CONNECTED
        ? "Scanner waiting"
        : "WiFi offline",
      "Not ready"
    );
  }
}

// =====================================================
// WIFI
// =====================================================

void connectWifi() {

  if (
    WiFi.status() == WL_CONNECTED ||
    (
      lastWifiAttempt != 0 &&
      millis() - lastWifiAttempt < WIFI_RETRY_MS
    )
  ) {
    return;
  }

  lastWifiAttempt = millis();

  statusNotReady();

  showMessage(
    "Connecting WiFi",
    WIFI_SSID
  );

  WiFi.mode(WIFI_STA);

  WiFi.begin(
    WIFI_SSID,
    WIFI_PASSWORD
  );

  for (
    int attempt = 0;
    attempt < 20 &&
    WiFi.status() != WL_CONNECTED;
    attempt++
  ) {
    waitWithDisplay(500);
  }

  if (WiFi.status() == WL_CONNECTED) {

    showMessage(
      "WiFi connected",
      WiFi.localIP().toString()
    );

    statusNotReady();

  } else {

    showMessage(
      "WiFi failed",
      "Will retry"
    );

    statusError();

    beepError();
  }

  waitWithDisplay(1200);

  if (
    WiFi.status() == WL_CONNECTED &&
    !scannerVerified
  ) {
    verifyScanner(true);
  }
}

// =====================================================
// SIMPLE JSON VALUE READER
// =====================================================

String jsonValue(
  const String &body,
  const String &key
) {
  const String marker =
    "\"" + key + "\":\"";

  int start = body.indexOf(marker);

  if (start < 0) {
    return "";
  }

  start += marker.length();

  int end =
    body.indexOf('"', start);

  if (end < 0) {
    return "";
  }

  return body.substring(start, end);
}

String jsonObject(const String &body, const String &key) {
  const String marker = "\"" + key + "\":";
  int start = body.indexOf(marker);
  if (start < 0) return "";
  start += marker.length();
  while (start < body.length() && body.charAt(start) == ' ') start++;
  if (start >= body.length() || body.charAt(start) != '{') return "";
  int depth = 0;
  for (int end = start; end < body.length(); end++) {
    if (body.charAt(end) == '{') depth++;
    if (body.charAt(end) == '}' && --depth == 0) return body.substring(start, end + 1);
  }
  return "";
}

// =====================================================
// VERIFY SCANNER WITH SERVER
// =====================================================

void verifyScanner(bool announce) {

  if (announce) statusNotReady();

  WiFiClientSecure client;
  client.setCACert(ISRG_ROOT_X1);

  HTTPClient request;

  if (
    !request.begin(
      client,
      STATUS_ENDPOINT
    )
  ) {
    scannerVerified = false;

    statusError();

    showMessage(
      "Server error",
      "Status failed"
    );

    if (announce) beepError();

    return;
  }

  request.setTimeout(12000);

  request.addHeader(
    "Accept",
    "application/json"
  );

  request.addHeader(
    "X-Device-Code",
    DEVICE_CODE
  );

  request.addHeader(
    "X-Device-Key",
    DEVICE_API_KEY
  );

  int statusCode =
    request.GET();

  String body =
    request.getString();

  request.end();

  scannerVerified =
    statusCode >= 200 &&
    statusCode < 300;

  lastStatusRefresh = millis();

  if (scannerVerified) {
    roomCode = jsonValue(body, "room_code");
    const String currentClass = jsonObject(body, "current_class");
    const String nextClass = jsonObject(body, "next_class");
    const String currentEvent = jsonObject(body, "current_event");
    currentCourseCode = jsonValue(currentClass, "course_code");
    currentComponentType = jsonValue(currentClass, "component_type");
    currentClassDay = jsonValue(currentClass, "day");
    currentClassStart = jsonValue(currentClass, "start_time");
    currentClassEnd = jsonValue(currentClass, "end_time");
    nextCourseCode = jsonValue(nextClass, "course_code");
    nextClassDay = jsonValue(nextClass, "day");
    nextClassStart = jsonValue(nextClass, "start_time");
    currentEventTitle = jsonValue(currentEvent, "title");

    if (announce) {
      showMessage("Scanner online", roomCode.length() ? roomCode + " ready" : "Assign a room");
      statusReady();
      waitWithDisplay(1200);
    }
    showReady();

  } else {

    roomCode = "";
    currentCourseCode = "";
    currentComponentType = "";
    nextCourseCode = "";
    currentEventTitle = "";

    statusError();

    showMessage(
      statusCode == 401 ? "Scanner rejected" : "Server unavailable",
      statusCode == 401 ? "Check device API key" : "Status check failed"
    );

    if (announce) beepError();
  }
}

// =====================================================
// SEND RFID UID TO SERVER
// =====================================================

void submitUid(const String &uid) {

  // ----------------------
  // No WiFi
  // ----------------------

  if (
    WiFi.status() != WL_CONNECTED
  ) {

    scannerVerified = false;

    statusError();

    showMessage(
      "No WiFi",
      "Scan not sent"
    );

    beepError();

    return;
  }

  // ----------------------
  // Processing
  // ----------------------

  statusProcessing();

  // ----------------------
  // HTTPS Client
  // ----------------------

  WiFiClientSecure client;
  client.setCACert(ISRG_ROOT_X1);

  HTTPClient request;

  if (
    !request.begin(
      client,
      SCAN_ENDPOINT
    )
  ) {

    statusError();

    showMessage(
      "Server error",
      "Try again"
    );

    beepError();

    return;
  }

  request.setTimeout(12000);

  request.addHeader(
    "Content-Type",
    "application/json"
  );

  request.addHeader(
    "Accept",
    "application/json"
  );

  request.addHeader(
    "X-Device-Code",
    DEVICE_CODE
  );

  request.addHeader(
    "X-Device-Key",
    DEVICE_API_KEY
  );

  // ----------------------
  // Submit UID
  // ----------------------

  String payload =
    "{\"uid\":\"" +
    uid +
    "\"}";

  int statusCode =
    request.POST(payload);

  String body =
    request.getString();

  request.end();

  Serial.printf(
    "HTTP %d: %s\n",
    statusCode,
    body.c_str()
  );

  // ----------------------
  // Parse response
  // ----------------------

  String mode =
    jsonValue(
      body,
      "mode"
    );

  String message =
    jsonValue(
      body,
      "message"
    );

  String attendanceStatus =
    jsonValue(
      body,
      "status"
    );

  String studentName =
    jsonValue(
      body,
      "name"
    );

  String responseCode =
    jsonValue(
      body,
      "code"
    );

  // ----------------------
  // Registration Mode
  // ----------------------

  if (
    statusCode >= 200 &&
    statusCode < 300 &&
    mode == "registration"
  ) {

    showMessage(
      "Card captured",
      "Confirm assignment on web"
    );

    statusReady();
    beepRegistration();

    // Scanner is still operational,
    // so return to READY state.
    statusReady();

    return;
  }

  // ----------------------
  // Successful Attendance
  // ----------------------

  if (
    statusCode >= 200 &&
    statusCode < 300
  ) {

    showMessage(
      studentName.length()
        ? studentName
        : "Attendance saved",

      attendanceStatus.length()
        ? "Attendance: " + attendanceStatus
        : "Success"
    );

    beepSuccess();

    statusReady();

    return;
  }

  // ----------------------
  // Rejected / No class / etc.
  // ----------------------

  statusError();

  if (statusCode < 0) {
    message = "Connection failed: " + String(HTTPClient::errorToString(statusCode).c_str());
  }

  showMessage(
    responseCode == "rate_limited" ? "Scanner busy" : "Scan rejected",
    message.length()
      ? message
      : "Server unavailable"
  );

  if (
    message.startsWith("Attendance is not open yet") ||
    message.startsWith("No class is scheduled")
  ) {
    beepScheduleRejected();
  } else {
    beepError();
  }
}

// =====================================================
// SETUP
// =====================================================

void setup() {

  Serial.begin(115200);

  // ----------------------
  // LEDs
  // ----------------------

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_YELLOW, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);

  // Starting = not ready
  statusNotReady();

  // ----------------------
  // Buzzer
  // ----------------------

  pinMode(
    BUZZER_PIN,
    OUTPUT
  );

  buzzerOff();

  // ----------------------
  // LCD
  // ----------------------

  Wire.begin(
    LCD_SDA,
    LCD_SCL
  );

  Wire.setClock(100000);

  lcd.init();
  lcd.backlight();

  showMessage(
    "Starting...",
    "Please wait"
  );

  beepStartup();

  // ----------------------
  // RFID
  // ----------------------

  SPI.begin(
    SCK_PIN,
    MISO_PIN,
    MOSI_PIN,
    SS_PIN
  );

  rfid.PCD_Init();

  delay(100);

  byte version =
    rfid.PCD_ReadRegister(
      MFRC522::VersionReg
    );

  Serial.print(
    "MFRC522 Version: 0x"
  );

  Serial.println(
    version,
    HEX
  );

  // ----------------------
  // RFID Hardware Check
  // ----------------------

  if (
    version == 0x00 ||
    version == 0xFF
  ) {

    statusError();

    showMessage(
      "RFID ERROR",
      "Check reader"
    );

    beepHardwareError();

    while (true) {
      updateDisplay();
      delay(10);
    }
  }

  Serial.println(
    "MFRC522 detected"
  );

  // ----------------------
  // Network
  // ----------------------

  statusNotReady();

  connectWifi();
}

// =====================================================
// LOOP
// =====================================================

void loop() {

  updateDisplay();

  if (WiFi.status() != WL_CONNECTED && scannerVerified) {
    scannerVerified = false;
    currentCourseCode = "";
    nextCourseCode = "";
  }

  connectWifi();

  if (
    WiFi.status() == WL_CONNECTED &&
    millis() - lastStatusRefresh >= STATUS_REFRESH_MS
  ) {
    verifyScanner(false);
  }

  // If WiFi has dropped, we're no longer ready.
  if (
    WiFi.status() != WL_CONNECTED
  ) {
    statusNotReady();
  }

  // ----------------------
  // Detect card
  // ----------------------

  if (
    !rfid.PICC_IsNewCardPresent() ||
    !rfid.PICC_ReadCardSerial()
  ) {
    return;
  }

  // ----------------------
  // If scanner isn't ready,
  // don't submit attendance.
  // ----------------------

  if (
    WiFi.status() != WL_CONNECTED ||
    !scannerVerified
  ) {

    statusError();

    showMessage(
      "Scanner not ready",
      "Try again later"
    );

    beepError();

    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();

    waitWithDisplay(1500);

    showReady();

    return;
  }

  // Immediate physical feedback
  beepCardDetected();

  // Processing = yellow
  statusProcessing();

  // ----------------------
  // Build UID
  // ----------------------

  String uid;

  for (
    byte i = 0;
    i < rfid.uid.size;
    i++
  ) {

    if (
      rfid.uid.uidByte[i] < 0x10
    ) {
      uid += "0";
    }

    uid += String(
      rfid.uid.uidByte[i],
      HEX
    );
  }

  uid.toUpperCase();

  Serial.print("RFID UID: ");
  Serial.println(uid);

  // ----------------------
  // Finish RFID session
  // ----------------------

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  // ----------------------
  // Duplicate protection
  // ----------------------

  if (
    uid == lastUid &&
    millis() - lastScanAt <
      CARD_COOLDOWN_MS
  ) {

    showReady();

    return;
  }

  lastUid = uid;
  lastScanAt = millis();

  // ----------------------
  // Show scanning
  // ----------------------

  showMessage(
    "Reading card...",
    uid
  );

  // ----------------------
  // Send to server
  // ----------------------

  submitUid(uid);

  // Keep the lane moving; the server has already saved or rejected the tap.
  waitWithDisplay(900);

  showReady();
}