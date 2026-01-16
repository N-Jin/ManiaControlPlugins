<?php
namespace NJin;

use cURL\Request;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\TimerListner;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Utils\Formatter;
use ManiaControl\Settings\Setting;
use ManiaControl\Settings\SettingManager;

/**
 * Chat Webhook Plugin for ManiaControl
 *
 * @author      N-Jin
 * @copyright   2026 N-Jin
 */

class ChatWebhookExporterPlugin implements CallbackListener, Plugin {

    /**
     * Constants
     */

    // Plugin Information
    const ID                 = 219;
    const VERSION            = 1.1;
    const PLUGIN_NAME        = 'Chat Webhook Exporter';
    const PLUGIN_AUTHOR      = 'N-Jin';
    const PLUGIN_DESCRIPTION = 'Exports chat messages to a Webhook. (e.g. Discord)';

    // Webhook Properties
    const SETTING_WEBHOOK_URL               = 'Webhook URL';
    const SETTING_WEBHOOK_ENABLE_COMMANDS   = 'Forward chat commands';
    /**
     * Private properties
     */
    /** @var    ManiaControl $maniaControl */
    private $maniaControl = null;

    /**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl) {
	}
    /**
     * Load the plugin
     *
     * @param  \ManiaControl\ManiaControl $maniaControl
     */
    public function load(ManiaControl $maniaControl) {
        $this->maniaControl = $maniaControl;

        //Callbacks
        $this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERDISCONNECT, $this, 'handlePlayerDisconnect');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERCHAT, $this, 'handlePlayerChat');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'handleSettingChanged');

        //Settings
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WEBHOOK_URL, '');
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WEBHOOK_ENABLE_COMMANDS, false);

        $webhookHttpCode = $this->checkWebhookUrl();
        $this->checkWebhookUrlMessage($webhookHttpCode);
    }

    /**
     * Unload the plugin and its resources
     */
    public function unload() {
            $this->maniaControl = null;
        }

    /**
     * Get plugin id
     *
     * @return  int
     */
    public static function getId() {
            return self::ID;
        }

    /**
     * Get Plugin Name
     *
     * @return  string
     */
    public static function getName() {
            return self::PLUGIN_NAME;
        }

    /**
     * Get Plugin Version
     *
     * @return  float
     */
    public static function getVersion() {
            return self::VERSION;
        }

    /**
     * Get Plugin Author
     *
     * @return  string
     */
    public static function getAuthor() {
            return self::PLUGIN_AUTHOR;
        }

    /**
     * Get Plugin Description
     *
     * @return  string
     */
    public static function getDescription() {
        return self::PLUGIN_DESCRIPTION;
    }
    
    /**
     * Send Webhook data to URL when player connects to server
     * 
     * @param   Player $player
     */
    public function handlePlayerConnect(Player $player) {
        $webhookUrl = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_URL);

        if ($this->checkWebhookUrl() === 200) {
            $serverName = Formatter::stripCodes($this->maniaControl->getClient()->getServerName());
            $playerZone = implode(", ",array_reverse(array_slice(explode("|",$player->path),2)));
            $data = [
                "username" => $serverName,
                "embeds" => [
                    [
                    "description" => Formatter::stripColors($player->nickname) ." (".$player->login.") from ". $playerZone . ($player->isSpectator ? " joined as Spectator." : " joined."),
                    "color" => hexdec("00CC00"),
                    "timestamp" => date('c', time())
                    ]
                ],
                "allowed_mentions" => [
                    "parse" => []
                ]
            ];

            $this->sendWebhookMessage($data);
        }
    }

    /**
     * Send Webhook data to URL when player disconnects from server
     * 
     * @param   Player $player
     */
    public function handlePlayerDisconnect(Player $player) {
        
        $webhookUrl = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_URL);

        if ($this->checkWebhookUrl() === 200) {
            $playTime   = Formatter::formatTimeH(time() - $player->joinTime);
            $serverName = Formatter::stripCodes($this->maniaControl->getClient()->getServerName());
            $playerZone = implode(", ",array_reverse(array_slice(explode("|",$player->path),2)));
            $data = [
                "username" => $serverName,
                "embeds" => [
                    [
                    "description" => Formatter::stripColors($player->nickname) ." (".$player->login.") from " .$playerZone. " left. ",
                    "color" => hexdec("CC0000"),
                    "footer" => [
                        "text" => "Playtime: " .$playTime,
                    ],
                    "timestamp" => date('c', time())
                    ]
                ],
                "allowed_mentions" => [
                    "parse" => []
                ]
            ];

            $this->sendWebhookMessage($data);
        }
    }

    /**
     * Exporting chat to webhook
     * 
     * @param $playerData
     */
    public function handlePlayerChat($playerData) {
		$login  = $playerData[1][1];
		$player = $this->maniaControl->getPlayerManager()->getPlayer($login);
        $webhookUrl = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_URL);
        $webhookEnableCommands = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_ENABLE_COMMANDS);
        $chatMessagePluginCommands = array("/me","/hi","/bb","/bye","/thx","/gg","/gl","/hf","/glhf","/ns","/n1","/lol","/lool","/brb","/bgm","/afk","/wp","/bm","/bootme","/rq","/ragequit");
        $nickname = "";
		if ($player) {
			$nickname = Formatter::stripColors($player->nickname);
            if ($this->checkWebhookUrl() === 200 && $playerData[1][0] !== 0) {
                $serverName = Formatter::stripCodes($this->maniaControl->getClient()->getServerName());
                if (in_array(explode(" ",$playerData[1][2], 2)[0], $chatMessagePluginCommands)) {
                    if (explode(" ", $playerData[1][2], 2)[0] == "/me") {
                        $data = [
                            "username" => $serverName,
                            "content"  => "**".$nickname."** *".explode(" ",$playerData[1][2], 2)[1]."*",
                            "allowed_mentions" => [
                                "parse" => []
                            ]
                        ];
                    }
                    else {
                        $data = [
                            "username" => $serverName,
                            "content"  => "**".$nickname."** *".$playerData[1][2]."*",
                            "allowed_mentions" => [
                                "parse" => []
                            ]
                        ];
                    }
                }
                else if (substr($playerData[1][2], 0, 1) == "/" && !$webhookEnableCommands) {
                    return;
                }
                else {
                    $data = [
                        "username" => $serverName,
                        "content"  => "**".$nickname."**: ".$playerData[1][2],
                        "allowed_mentions" => [
                            "parse" => []
                        ]
                    ];
                }

                $this->sendWebhookMessage($data);
            }
		}
    }

    public function handleSettingChanged(Setting $setting) {
        if (!$setting->belongsToClass($this)) {
			return;
		}
        if ($setting->setting == self::SETTING_WEBHOOK_URL) {
            $webhookHttpCode = $this->checkWebhookUrl();
            if ($webhookHttpCode !== -1) $this->checkWebhookUrlMessage($webhookHttpCode);
        }
    }

    /**
     * Check if Webhook URL exists
     * returns HTTP Code
     * 
     * @param   $webhookUrl
     * @return  int
     */
    private function checkWebhookUrl(): int 
    {
        $webhookUrl = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_URL);
        if (empty($webhookUrl)) {
            $error = 'No Webhook URL set. Check your settings!';
			$this->maniaControl->getChat()->sendErrorToAdmins($error);
            return -1;
        }
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        return $httpCode;
    }

    private function checkWebhookUrlMessage(int $webhookHttpCode) {
        if ($webhookHttpCode === 200) {
            $connected = "Webhook connected!";
			$this->maniaControl->getChat()->sendSuccessToAdmins($connected, 2);
        }
        else if ($webhookHttpCode === 404) {
            $error = "Webhook URL does not exist. check that your URL is valid!";
			$this->maniaControl->getChat()->sendErrorToAdmins($error);
            return;
        }
        else if ($webhookHttpCode !== -1) {
            $error = "Something went wrong. Unexpected response: HTTP $webhookHttpCode\n";
			$this->maniaControl->getChat()->sendErrorToAdmins($error);
            return;
        }
        else if ($webhookHttpCode === -1) {
            return;
        }
    }

    /**
     * Sending webhook message
     * @param array $data
     */

    private function sendWebhookMessage($data) {
        $webhookUrl = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WEBHOOK_URL);

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if ($response === false) {
            echo "Curl error: " . curl_error($ch);
            $this->maniaControl->getChat()->sendErrorToAdmins($error);
        }
        curl_close($ch);
    }
}
