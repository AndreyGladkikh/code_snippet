<?php


namespace App\Model\Ticket\UseCase\Ticket\Connection;


use App\Model\Exception\DataNotValidException;
use App\Model\Exception\DuplicateKeyException;
use App\Model\Exception\NotFoundException;
use App\Model\Exception\StatusNotValidException;
use App\Model\Location\Entity\House\House;
use App\Model\Location\Entity\House\HouseRepository;
use App\Model\Location\Entity\Organization\Organization;
use App\Model\Ticket\Entity\Channel\Channel;
use App\Model\Ticket\Entity\Channel\ChannelRepository;
use App\Model\Ticket\Entity\Channel\Id as ChannelId;
use App\Model\Ticket\Entity\Customer\Account;
use App\Model\Ticket\Entity\Customer\AccountRepository;
use App\Model\Ticket\Entity\Customer\Contact;
use App\Model\Ticket\Entity\Customer\ContactRepository;
use App\Model\Ticket\Entity\Customer\Customer;
use App\Model\Ticket\Entity\Customer\Id as CustomerId;
use App\Model\Ticket\Entity\Customer\Passport;
use App\Model\Ticket\Entity\Customer\PassportType;
use App\Model\Ticket\Entity\ITService\Id as ITServiceId;
use App\Model\Ticket\Entity\ITService\ITService;
use App\Model\Ticket\Entity\ITService\ITServiceRepository;
use App\Model\Ticket\Entity\ITService\ITServiceWorkType;
use App\Model\Ticket\Entity\ITServiceServiceType\ITServiceServiceType;
use App\Model\Ticket\Entity\ITServiceServiceType\ITServiceServiceTypeRepository;
use App\Model\Ticket\Entity\OperationType\OperationType;
use App\Model\Ticket\Entity\OperationType\OperationTypeRepository;
use App\Model\Ticket\Entity\Provider\ProviderRepository;
use App\Model\Ticket\Entity\Provider\Id as ProviderId;
use App\Model\Ticket\Entity\Reason\Reason;
use App\Model\Ticket\Entity\WorkType\Id;
use App\Model\Ticket\Entity\WorkType\WorkType;
use App\Model\Ticket\Entity\WorkType\WorkTypeRepository;
use App\Model\Ticket\UseCase\Ticket\AbstractHandlerDataFetcher;
use App\Model\User\Entity\User\Id as UserId;
use App\Model\User\Entity\User\User;
use App\Model\User\Entity\User\UserRepository;
use App\Model\ValueObject\Phone;
use App\Model\ValueObject\AdditionalPhoneCollection;
use App\Services\Catalog\CatalogService;
use Exception;

class HandlerDataFetcher extends AbstractHandlerDataFetcher
{
    /**
     * @var ChannelRepository
     */
    private $channels;
    /**
     * @var UserRepository
     */
    private $users;
    /**
     * @var AccountRepository
     */
    private $accounts;
    /**
     * @var ContactRepository
     */
    private $contacts;
    /**
     * @var ITServiceRepository
     */
    private $itservices;
    /**
     * @var ProviderRepository
     */
    private $providers;
    /**
     * @var ITServiceServiceTypeRepository
     */
    private $itServiceServiceTypes;
    /**
     * @var CatalogService
     */
    private $catalogService;
    /**
     * @var OperationTypeRepository
     */
    private $operationTypes;
    /**
     * @var WorkTypeRepository
     */
    private $workTypes;

    public function __construct(
        ProviderRepository $providers,
        ITServiceRepository $itservices,
        HouseRepository $houses,
        UserRepository $users,
        ChannelRepository $channels,
        AccountRepository $accounts,
        ContactRepository $contacts,
        ITServiceServiceTypeRepository $itServiceServiceTypes,
        OperationTypeRepository $operationTypes,
        WorkTypeRepository $workTypes,
        CatalogService $catalogService)
    {
        parent::__construct(
            $houses,
            $users);
        $this->channels = $channels;
        $this->users = $users;
        $this->accounts = $accounts;
        $this->contacts = $contacts;
        $this->itservices = $itservices;
        $this->providers = $providers;
        $this->itServiceServiceTypes = $itServiceServiceTypes;
        $this->catalogService = $catalogService;
        $this->operationTypes = $operationTypes;
        $this->workTypes = $workTypes;
    }

    /**
     * @param string|null $id
     * @return Channel|null
     */
    public function findChannel(?string $id) : ?Channel
    {
        if(empty($id)){
            return null;
        }
        $channel = $this->channels->find(new ChannelId($id));
        if($channel !== null && $channel->isBlocked()){
            throw new StatusNotValidException("Channel {$channel->getName()} status is not active.");
        }
        return $channel;
    }

    /**
     * @param string|null $id
     * @param bool $isOpportunity
     * @return User|null
     */
    public function findAgent(?string $id, $isOpportunity = false) : ?User
    {
        if(empty($id)){
            return null;
        }
        $agent = $this->users->findAgent(new UserId($id));
        if($agent !== null && !$isOpportunity && $agent->isBlocked()){
            throw new StatusNotValidException("Agent {$agent->getName()} status is not active.");
        }
        return $agent;
    }

    /**
     * @param CommandCustomer $customer
     * @return Customer|null
     * @throws Exception
     */
    public function findCustomer(CommandCustomer $customer) : ?Customer
    {
        if($customer->id !== null){
            if($customer->type === CommandCustomer::TYPE_CONTACT){
                return $this->contacts->get(new CustomerId($customer->id));
            }
            if($customer->type === CommandCustomer::TYPE_ACCOUNT){
                return $this->accounts->get(new CustomerId($customer->id));
            }
        }
        if($customer->id === null){
            if($customer->type === CommandCustomer::TYPE_CONTACT){
                if($customer->contact->isWithoutPassportData !== true
                    && (null !== $this->contacts->findByPassportNumberAndSeries(
                            $customer->contact->passportNumber,
                            $customer->contact->passportSeries))
                ){
                    throw new DuplicateKeyException('Contact by passport number, series found.');
                }
                return new Contact(
                    CustomerId::next(),
                    mb_convert_case($customer->contact->name, MB_CASE_TITLE),
                    $customer->contact->dateOfBirth,
                    new Phone($customer->contact->mainPhone),
                    $customer->contact->isWithoutPassportData === true ? new Passport() :
                        new Passport(
                            $customer->contact->passportNumber,
                            $customer->contact->passportIssuedBy,
                            $customer->contact->passportDateOfIssue,
                            new PassportType($customer->contact->passportType),
                            $customer->contact->passportSeries ?: null
                        ),
                    new AdditionalPhoneCollection($customer->contact->phones),
                    $customer->contact->isWithoutPassportData
                );
            }
            if($customer->type === CommandCustomer::TYPE_ACCOUNT){
                if($customer->account->isWithoutPassportData !== true
                    && (null !== $this->accounts->findByInnKpp(
                            $customer->account->inn,
                            $customer->account->kpp))
                ){
                    throw new DuplicateKeyException('Account by inn, kpp found.');
                }
                return new Account(
                    CustomerId::next(),
                    mb_convert_case($customer->account->name, MB_CASE_TITLE),
                    new Phone($customer->account->mainPhone),
                    $customer->account->isWithoutPassportData === true ? null : $customer->account->inn,
                    $customer->account->isWithoutPassportData === true ? null : $customer->account->kpp,
                    $customer->account->description,
                    new AdditionalPhoneCollection($customer->account->phones),
                    $customer->account->isWithoutPassportData
                );
            }
        }
        return null;
    }

    public function getITService(string $id) : ITService
    {
        $itservice = $this->itservices->get(new ITServiceId($id));
        if($itservice->isBlocked()){
            throw new StatusNotValidException("ITService {$itservice->getName()} status is not active.");
        }
        return $itservice;
    }

    public function findOperationType(?string $id) : ?OperationType
    {
        if (empty($id)) {
            return null;
        }
        $operationType = $this->operationTypes->get($id);
        if($operationType->isBlocked()){
            throw new StatusNotValidException("Operation type {$operationType->getName()} status is not active.");
        }
        return $operationType;
    }

    public function getWorkType(ITService $itService, ?Reason $reason, ?array $commandItServiceServiceTypes, House $house, ?User $user = null): WorkType
    {
        if ($user && $user->isDispatcherTtk()) {
            return $this->workTypes->get(new Id(WorkType::CONNECTION_TTK_SUBSCRIBER_ID));
        }
        $commandItServiceServiceTypeIds = array_map(function($item) {return $item->getId()->getValue();}, $commandItServiceServiceTypes);
        $workTypes = $itService->getItservicesWorkTypes()->toArray();
        /** @var ITServiceWorkType $item */
        foreach ($workTypes as $item) {
            $itServiceServiceTypeIds = array_map(function($item) {return $item->getId()->getValue();}, $item->getItServiceServiceTypes()->toArray());
            $workTypeHasServiceTypeSet = empty(array_diff($itServiceServiceTypeIds, $commandItServiceServiceTypeIds))
                && empty(array_diff($commandItServiceServiceTypeIds, $itServiceServiceTypeIds));
            if ($reason) {
                $workTypeHasReason = in_array(
                    $reason->getId()->getValue(),
                    array_map(function($item) {return $item->getId()->getValue();}, $item->getReasons()->toArray())
                );
                if ($workTypeHasServiceTypeSet && $workTypeHasReason) {
                    return $item->getWorkType();
                }
            } else {
                if ($workTypeHasServiceTypeSet && $itService->isServiceTypeReasonNotRequired()) {
                    return $item->getWorkType();
                }
            }
        }
        if (!$itService->isServiceTypeReasonNotRequired()) {
            throw new NotFoundException('Не найден вид работы.');
        } else {
            throw new NotFoundException('Не найден вид работы соответствующий указанным тематике и виду услуги.');
        }
    }

    public function findItServiceServiceTypes(?array $itServiceServiceTypeId) : array
    {
        $itServiceServiceTypes = $this->itServiceServiceTypes->getByIds($itServiceServiceTypeId);
        return $itServiceServiceTypes;
    }

    public function findProvider(?string $id, House $house)
    {
        if(empty($id)){
            return null;
        }
        $provider = $this->providers->find(new ProviderId($id));
        if($provider !== null) {
            if ($provider->isBlocked()) {
                throw new StatusNotValidException("Provider {$provider->getName()} status is not active.");
            }
        }
        return $provider;
    }
}