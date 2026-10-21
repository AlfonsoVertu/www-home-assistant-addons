import time
import logging
import shutil
import sys
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

# Parsing args
if len(sys.argv) < 3:
    print("Usage: python3 selenium_screenshot_antibot.py <url> <output_path>")
    sys.exit(1)
url = sys.argv[1]
dest_path = sys.argv[2]

# Configura il logging (console + file)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[
        logging.FileHandler("selenium_screenshot.log"),
        logging.StreamHandler()
    ]
)
log = logging.getLogger()

log.info("Inizio script Selenium per screenshot antibot")

chrome_options = Options()
chrome_options.add_argument("--headless=new")
chrome_options.add_argument("--disable-blink-features=AutomationControlled")
chrome_options.add_argument("--disable-infobars")
chrome_options.add_argument("--disable-extensions")
chrome_options.add_argument("--no-sandbox")
chrome_options.add_argument("--disable-gpu")
chrome_options.add_argument("--window-size=1200,800")
chrome_options.add_experimental_option("excludeSwitches", ["enable-automation"])
chrome_options.add_experimental_option('useAutomationExtension', False)
chrome_options.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36')

log.info("Opzioni Chrome configurate (antibot attive)")

try:
    driver = webdriver.Chrome(options=chrome_options)
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
      "source": """
        Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
        window.navigator.chrome = { runtime: {} };
        Object.defineProperty(navigator, 'languages', {get: () => ['it-IT', 'it']});
        Object.defineProperty(navigator, 'plugins', {get: () => [1,2,3,4,5]});
      """
    })
    log.info("Driver Chrome avviato e tecniche antibot iniettate")
    log.info(f"Navigazione verso {url}")
    driver.get(url)
    log.info("Attesa caricamento pagina (2s)")
    time.sleep(2)
    images = driver.find_elements(By.TAG_NAME, "img")
    if images:
        log.info(f"Trovate {len(images)} immagini nella pagina.")
    else:
        log.warning("Nessuna immagine trovata, procedo comunque allo screenshot dell’intera pagina.")
    screenshot_path = "/tmp/cover_chatcontrol2.png"
    driver.save_screenshot(screenshot_path)
    log.info(f"Screenshot salvato temporaneamente in {screenshot_path}")
    driver.quit()
    log.info("Driver Chrome chiuso correttamente")
    shutil.move(screenshot_path, dest_path)
    log.info(f"Spostato screenshot in {dest_path}")
    log.info("Script completato con successo! Screenshot pronto per essere usato come copertina.")
except Exception as e:
    log.error(f"Errore nell'esecuzione dello script: {e}")
    try:
        driver.quit()
        log.info("Driver Chrome chiuso dopo errore.")
    except:
        pass
