<?php defined('B_PROLOG_INCLUDED') || die;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Invest\Portal\Map\Exchange\Platforms;
use \Invest\Portal\Tools\Utils;
use Invest\Portal\Orm;
use \Bitrix\Main\Localization\Loc;

class InvestPortalInfrastructureServicesList extends \CBitrixComponent implements Controllerable
{
    /**
     * @throws Main\LoaderException
     * @throws \Bitrix\Main\LoaderException
     */
    public function __construct($component = null)
    {
        Loader::includeModule(PORTAL_MODULE_ID);
        Loader::includeModule('iblock');
        parent::__construct($component);
    }

    private $count = 4;
    private $arRadioFilter = ['MUNICIPAL'];
    private $arGuideFilter = ['MUNICIPAL'];

    public function configureActions(): array
    {
        $configureActions = [];

        $actionNames = ['getInfrastructureServicesList', 'getFilterInfrastructureServicesList'];
        foreach ($actionNames as $actionName) {
            $configureActions[$actionName] = [
                'prefilters' => [
                    new ActionFilter\HttpMethod(
                        [
                            ActionFilter\HttpMethod::METHOD_GET,
                            ActionFilter\HttpMethod::METHOD_POST,
                        ]
                    ),
                ],
                'postfilters' => [],
            ];
        }

        return $configureActions;
    }

    public function getInfrastructureServicesListAction(int $page = 1, string $sort = 'asc', array $filter = [])
    {
        if(!$filter) return [];
        $class = '\Bitrix\Iblock\Elements\ElementInfrastructureServices' . Utils::getLangApiCode() . 'Table';
        $filter['=ACTIVE'] = 'Y';
        $elements = $class::getList([
            'select' => ['ID', 'NAME', 'CODE', 'PREVIEW_PICTURE', 'LINK_VALUE' => 'LINK.VALUE', 'PHONE_VALUE' => 'PHONE.VALUE', 'EMAIL_VALUE' => 'EMAIL.VALUE'],
            'count_total' => true,
            'filter' => $filter,
            // 'limit' => 4,
            // 'offset' => (($page - 1) * 4),
            'order' => ['SORT' => $sort],
            'cache' => ['ttl' => 3600],
        ]);
        $arResult = [
            'total' => $elements->getCount(),
            'page' => $page,
            'list' => []
        ];
        while($item = $elements->fetch()){
            $arResult['list']['services'][] = [
                "id" => $item['ID'],
                "title" => $item['NAME'],
                "link" => $item['LINK_VALUE'],
                "images" => CFile::GetPath($item['PREVIEW_PICTURE']),
                'phone' => $item['PHONE_VALUE'],
                'email' => $item['EMAIL_VALUE']
            ];
        }
        $class = '\Bitrix\Iblock\Elements\ElementMunicipality' . Utils::getLangApiCode() . 'Table';
        // $filter['!NPA.VALUE'] = false;
        if($filter['=MUNICIPAL.VALUE']){
            $filter['=CODE'] = $filter['=MUNICIPAL.VALUE'];
            unset($filter['=MUNICIPAL.VALUE']);
        }
        $elements = $class::getList([
            'select' => ['NAME', 'NPA_VALUE' => 'NPA.VALUE', 'CONTACT_VALUE' => 'CONTACT.VALUE'],
            'filter' => $filter,
            'cache' => ['ttl' => 3600],
        ]);
        $npaIDs = [];
        $arContacts = [];
        while($item = $elements->fetch()){
            $arContacts[] = ceil($item['CONTACT_VALUE']);
            if(ceil($item['NPA_VALUE'])){
                $npaIDs[] = ceil($item['NPA_VALUE']);
                $arResult['list']['npa'][] = [
                    "title" => $item['NAME'],
                    "link" => ceil($item['NPA_VALUE']),
                    'title_link' => Loc::getMessage('NAME_NPA_LINK')
                ];
            }
        }
        if($npaIDs){
            $class = '\Bitrix\Iblock\Elements\ElementDocuments' . Utils::getLangApiCode() . 'Table';
            $elements = $class::getList([
                'select' => ['ID', 'LINK_VALUE' => 'LINK.VALUE', 'FILE_VALUE' => 'FILE.VALUE'],
                'filter' => ['=ID' => $npaIDs, '=ACTIVE' => 'Y'],
                'cache' => ['ttl' => 3600],
            ]);
            $arLinks = [];
            while($item = $elements->fetch()){
                $arLinks[$item['ID']] = $item['FILE_VALUE'] ? CFile::GetPath($item['FILE_VALUE']) : $item['LINK_VALUE'];
            }
        }
        foreach($arResult['list']['npa'] as $key => $item){
            $arResult['list']['npa'][$key]['link'] = $arLinks[$item['link']] ?: '';
        }
        
        $class = '\Bitrix\Iblock\Elements\ElementWorkers' . Utils::getLangApiCode() . 'Table';
        $item = $class::getList([
            'order' => ['ID' => 'ASC'],
            'select' => ['PREVIEW_PICTURE', 'NAME', 'POSITION_VALUE' => 'POSITION.VALUE', 'PHONE_VALUE' => 'PHONE.VALUE', 'EMAIL_VALUE' => 'EMAIL.VALUE'],
            'filter' => ['=ID' => $arContacts, '=ACTIVE' => 'Y'],
            'limit' => 1,
            'cache' => ['ttl' => 3600],
        ])->fetch();
        $arResult['contact'] = [
            'name' => $item['NAME'],
            'position' => $item['POSITION_VALUE'],
            'phone' => $item['PHONE_VALUE'],
            'email' => $item['EMAIL_VALUE'],
            'picture' => CFile::GetPath($item['PREVIEW_PICTURE'])
        ];

        return $arResult;
    }

    public function getFilterInfrastructureServicesListAction()
    {
        $class = '\Bitrix\Iblock\Elements\ElementInfrastructureServices' . Utils::getLangApiCode() . 'Table';
        $iblockId = Utils::getLangIblockIdByCode('infrastructure_services');
        $arProps = CIBlockSectionPropertyLink::GetArray($iblockId);
        $propsSmartFilter = [];
        foreach($arProps as $prop){
            if($prop['SMART_FILTER'] == 'Y') $propsSmartFilter[$prop['PROPERTY_ID']] = $prop;
        }
        $arGuide = [];
        $orderName = 'UF_NAME' . Utils::$siteLangCodesHL[SITE_ID];
        $obMunicipal = Orm\MunicipalitiesTable::getList([
            'select' => ['UF_NAME', 'UF_NAME_EN', 'UF_NAME_AR', 'UF_NAME_CH', 'UF_XML_ID'],
            'order' => [$orderName => 'asc']
        ]);
        while($municipal = $obMunicipal->Fetch()){
            $arGuide['MUNICIPAL'][$municipal['UF_XML_ID']] = [
                'id' => $municipal['UF_XML_ID'],
                'value' => $municipal['UF_NAME' . Utils::$siteLangCodesHL[SITE_ID]]
            ];
        }
        $obProps = CIBlockProperty::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y', 'IBLOCK_ID' => $iblockId]);
        while($prop = $obProps->Fetch()){
            if(!in_array($prop['ID'], array_keys($propsSmartFilter))) continue;
            $item = [
                'code' => $prop['CODE'],
                'name' => (Loc::getMessage($prop['CODE']) ?: $prop['NAME']),
                'sort' => $prop['SORT'],
                'unitsType' => $propsSmartFilter[$prop['ID']]['FILTER_HINT']
            ];
            if($prop['PROPERTY_TYPE'] == 'L'){
                $item['type'] = in_array($prop['CODE'], $this->arRadioFilter) ? 'select' : 'multiselect';
                $item['values'] = $arEnum[$prop['ID']];
            }elseif($prop['PROPERTY_TYPE'] == 'N'){
                $item['type'] = 'number';
                $minProp = CIBlockElement::GetList(
                    ['PROPERTY_' . $prop['CODE'] => 'asc'],
                    ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', '>PROPERTY_' . $prop['CODE'] => '0'],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'IBLOCK_ID', 'PROPERTY_' . $prop['CODE']]
                )->Fetch();
                $item['values']['min'] = $minProp['PROPERTY_' . $prop['CODE'] . '_VALUE'];
                $maxProp = CIBlockElement::GetList(
                    ['PROPERTY_' . $prop['CODE'] => 'desc'],
                    ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', '>PROPERTY_' . $prop['CODE'] => '0'],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'IBLOCK_ID', 'PROPERTY_' . $prop['CODE']]
                )->Fetch();
                $item['values']['max'] = $maxProp['PROPERTY_' . $prop['CODE'] . '_VALUE'];
            }elseif(in_array($prop['CODE'], $this->arGuideFilter)){
                $item['type'] = in_array($prop['CODE'], $this->arRadioFilter) ? 'select' : 'multiselect';
                $item['values'] = array_values($arGuide[$prop['CODE']]);
            }
            $arResult['items'][] = $item;
        }

        return $arResult;
    }
}