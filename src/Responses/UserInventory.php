<?php

namespace SteamApi\Responses;

use Curl\MultiCurl;
use SteamApi\Interfaces\ResponseInterface;
use SteamApi\Mixins\Mixins;

class UserInventory implements ResponseInterface
{
    private $data;

    public function __construct($response)
    {
        $this->data = $this->decodeResponse($response);
    }

    public function response()
    {
        return $this->data;
    }

    public function __destruct()
    {
        unset($this->data);
    }

    private function decodeResponse($response)
    {
        if (!is_array($response)) {
            $data = json_decode($response, true);

            if (is_null($data) || array_key_exists('error', $data))
                return false;

            return self::parseInventory($data);
        } else {
            $returnData = $response;

            $data = json_decode($response['response'], true);

            if (is_null($data) || array_key_exists('error', $data)) {
                $returnData['response'] = false;
                return $returnData;
            }

            $returnData['response'] = self::parseInventory($data);

            return $returnData;
        }
    }

    private function parseInventory($data)
    {
        $multi_curl = new MultiCurl();
        $multi_curl->setConcurrency(100);

        $returnData = [];

        $slot = 0;

        foreach ($data['assets'] as &$asset) {
            foreach ($data['descriptions'] as &$description) {
                if ($asset['classid'] === $description['classid'] &&
                    $asset['instanceid'] === $description['instanceid'])
                {

                    $inspectData = Mixins::createSteamLink($asset, $description);
                    $description['inspectLink'][$asset['assetid']] = $inspectData['inspectLink'];

                    $asset['slot'] = ++$slot;

                    if ($inspectData['inspectable'])
                        $multi_curl->addGet('https://api.csgofloat.com/', array(
                            'url' => $inspectData['inspectLink']
                        ));
                    else
                        $returnData[] = $this->completeData($asset, $description);

                    break;
                }
            }
        }

        $multi_curl->success(function($instance) use(&$returnData, $data) {

            $parts = parse_url($instance->url);
            parse_str($parts['query'], $query);

            $itemInfo = json_decode(json_encode($instance->response), true);

            foreach ($data['assets'] as $asset) {
                foreach ($data['descriptions'] as $description) {
                    if ($asset['classid'] === $description['classid'] &&
                        $asset['instanceid'] === $description['instanceid'] &&
                        array_key_exists($asset['assetid'], $description['inspectLink']) &&
                        $description['inspectLink'][$asset['assetid']] === $query['url'])
                        $returnData[] = $this->completeData($asset, $description, $query['url'], $itemInfo);
                }
            }
        });

        $multi_curl->error(function ($instance) use (&$returnData, $data) {

            $parts = parse_url($instance->url);
            parse_str($parts['query'], $query);

            foreach ($data['assets'] as $asset) {
                foreach ($data['descriptions'] as $description) {
                    if ($asset['classid'] === $description['classid'] &&
                        $asset['instanceid'] === $description['instanceid'] &&
                        array_key_exists($asset['assetid'], $description['inspectLink']) &&
                        $description['inspectLink'][$asset['assetid']] === $query['url'])
                        $returnData[] = $this->completeData($asset, $description, $query['url']);
                }
            }
        });

        $multi_curl->start();

        usort($returnData, function($a, $b) {
            return $a['slot'] <=> $b['slot'];
        });

        return $returnData;
    }

    private function completeData($asset, $description, $inspectLink = '', $inspectItem = []): array
    {
        $steamImgUrl = "https://steamcommunity-a.akamaihd.net/economy/image/";
        $cloudFlareUmgUrl = "https://community.cloudflare.steamstatic.com/economy/image/";

        $baseInfo =  [
            'assetid'         => $asset['assetid'],
            'classid'         => $asset['classid'],
            'instanceid'      => $asset['instanceid'],
            'amount'          => $asset['amount'],
            'slot'            => $asset['slot'],

            'name'            => $description['market_hash_name'],
            'nameColor'       => $description['name_color'],
            'type'            => $description['type'],
            'image'           => $steamImgUrl . $description['icon_url'],
            'imageLarge'      => isset($description['icon_url_large']) ? $steamImgUrl . $description['icon_url_large'] : null,
            'image_cf'        => $cloudFlareUmgUrl . $description['icon_url'],
            'imageLarge_cf'   => isset($description['icon_url_large']) ? $cloudFlareUmgUrl . $description['icon_url_large'] : null,
            'withdrawable_at' => $description['market_tradable_restriction'],
            'marketable'      => !!$description['marketable'],
            'tradable'        => !!$description['tradable'],
            'commodity'       => !!$description['commodity'],
            'inspectLink'     => $inspectLink
        ];

        $addInfo = [];

        if (isset($description['fraudwarnings'])) {
            $baseInfo['nameTag'] = Mixins::parseNameTag($description['fraudwarnings'][0]);
        }

        if ($inspectItem && array_key_exists('iteminfo', $inspectItem)) {
            $addInfo = [
                'condition'  => $inspectItem['iteminfo']['wear_name'],
                'float'      => $inspectItem['iteminfo']['floatvalue'],
                'paintseed'  => $inspectItem['iteminfo']['paintseed'],
                'paintindex' => $inspectItem['iteminfo']['paintindex'],
                'stickers'   => $inspectItem['iteminfo']['stickers']
            ];
        }

        return array_merge($baseInfo, $addInfo);
    }
}