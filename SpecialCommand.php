<?php

use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Transaction;
use Cycle\ORM\TransactionInterface;
use Glavfinans\Core\Addresses\AddressByDadata;
use Glavfinans\Core\Client\Inn;
use Glavfinans\Core\Client\SkibaApiClient;
use Glavfinans\Core\CreditHistory\EquifaxXmlFactory;
use Glavfinans\Core\DaData\DaDataClient;
use Glavfinans\Core\Documents\CantSaveFileException;
use Glavfinans\Core\Documents\Doc\DocAgreementNew;
use Glavfinans\Core\Documents\Doc\DocFormPODFT;
use Glavfinans\Core\Documents\Doc\DocNoPenalty;
use Glavfinans\Core\Documents\Doc\DocPact;
use Glavfinans\Core\Documents\Doc\DocProlong;
use Glavfinans\Core\Documents\Xls\XlsCreditHolidaysReportExport;
use Glavfinans\Core\Entity\Account\AccountRepositoryInterface;
use Glavfinans\Core\Entity\Account\RegistrationMode;
use Glavfinans\Core\Entity\Address\AddressService;
use Glavfinans\Core\Entity\Bankrupt\BankruptConfirmStatus;
use Glavfinans\Core\Entity\Bankrupt\BankruptFedParse\BankruptFedParse;
use Glavfinans\Core\Entity\Bankrupt\BankruptFedParse\BankruptFedParseRepositoryInterface;
use Glavfinans\Core\Entity\Bankrupt\BankruptRepositoryInterface;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequest;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequestRepositoryInterface;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequestResultFactory;
use Glavfinans\Core\Entity\Charge\ChargeRepositoryInterface;
use Glavfinans\Core\Entity\Comment\CommentRepositoryEntityInterface;
use Glavfinans\Core\Entity\CourtCollectionHistory\CourtCollectionCategoryValueType;
use Glavfinans\Core\Entity\CreditApplication\CreditApplicationRepositoryInterface;
use Glavfinans\Core\Entity\CreditHolidays\ICreditHolidaysRepository;
use Glavfinans\Core\Entity\EsiaRequestPersonData\EsiaRequestPersonDataRepositoryInterface;
use Glavfinans\Core\Entity\FsspRequest\FsspRequest;
use Glavfinans\Core\Entity\FsspRequest\FsspRequestRepositoryInterface;
use Glavfinans\Core\Entity\FsspRequest\FsspRequestStatus;
use Glavfinans\Core\Entity\IncomingTransfer\IncomingTransferRepositoryInterface;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentDestination;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentType;
use Glavfinans\Core\Entity\LeadPostback\ILeadPostbackRepository;
use Glavfinans\Core\Entity\LeadPostback\LeadPostback;
use Glavfinans\Core\Entity\Loan\LoanRepositoryInterface;
use Glavfinans\Core\Entity\LoanHistory\LoanHistoryRepositoryInterface;
use Glavfinans\Core\Entity\LoanRestructuring\LoanRestructuringRepositoryInterface;
use Glavfinans\Core\Entity\LoanSale\LoanSaleRepositoryInterface;
use Glavfinans\Core\Entity\Staff\IStaffRepository;
use Glavfinans\Core\Entity\WriteOffDebtPart\WriteOffDebtPartRepository;
use Glavfinans\Core\Entity\WriteOffDebtPart\WriteOffDebtPartRepositoryInterface;
use Glavfinans\Core\Exception\BaseException;
use Glavfinans\Core\Exception\EmptyValueException;
use Glavfinans\Core\Exception\NotFoundException;
use Glavfinans\Core\GFKCache\GFKCacheInterface;
use Glavfinans\Core\Insurance\FactoryInsuranceLife;
use Glavfinans\Core\Kernel\KernelInfo;
use Glavfinans\Core\Kernel\Task\ITaskPusher;
use Glavfinans\Core\Loan\Status;
use Glavfinans\Core\Recalc\Godzilla;
use Glavfinans\Core\Recalc\GodzillaFactory;
use Glavfinans\Core\Recalc\Models\Recalc;
use Glavfinans\Core\Report\Task\GenerateAverageSalary;
use Glavfinans\Core\Services\DialerWorkflowService;
use Glavfinans\Core\Services\LoanForStatService;
use Glavfinans\Core\Services\PercentRateService;
use Glavfinans\Core\TaxService\TaxServiceClient;
use Glavfinans\Domain\CommonContext\Services\CourtCollectionHistoryService;
use Glavfinans\Domain\ValueObject\Client\Gender;
use Glavfinans\Domain\ValueObject\Equifax\ReportVersion;
use Glavfinans\Repository\IncomingTransfer\IncomingTransferRepositoryInterface as IncomingTransferRepositoryInterfaceEntity;
use Glavfinans\Repository\Loan\LoanRepositoryInterface as LoanRepositoryInterfaceEntuty;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Одноразовый скрипт
 * Class SpecialCommand
 * php yiic.php Special checkLoanSum
 * php yiic.php Special checkBillSum
 * php yiic.php Special recalculateLoansAfter2015 --id=734    пересчёт займа с id=734
 * php yiic.php Special setSPD
 */
class SpecialCommand extends GfkBaseConsoleCommand
{
    /**
     * Перегенерация существующих договоров
     * Пример использования. Необходимо запускать от пользователя www-data. Перегенерирует договоры одобренные с
     * 2017-06-01 по сегодня
     * sudo -u www-data ./yiic special reGenerateDocs --type='' --begin='2017-06-01'
     *
     * @param LoggerInterface $logger
     * @param string $type ''|'long'
     * @param $begin
     * @param null $end
     * @throws BaseException
     * @throws CantSaveFileException
     * @throws Exception
     */
    public function actionReGenerateDocs(LoggerInterface $logger, $type, $begin, $end = null)
    {

        $beginDate = DateFmt::dateFromDB($begin);
        $beginDate->setTime(00, 00, 00);

        if (null === $end) {
            $endDate = new DateTime();
        } else {
            $endDate = DateFmt::dateFromDB($end);
        }

        $endDate->setTime(23, 59, 59);

        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition('issue_date', $beginDate->format(DateFmt::DT_DB), $endDate->format(DateFmt::DT_DB));
        $criteria->addInCondition('loan_status_id', [
            Status::ACTIVE,
            Status::PAY_OFF,
            Status::WAITING_TRANSFER,
            Status::TRANSFER_OK,
            Status::TRANSFER_FAIL,
        ]);

        /** @var LoanBase[] $loans */
        $loans = LoanBase::model()->findAll($criteria);

        foreach ($loans as $loan) {
            if ('long' === $type xor $loan->getApp()->getType()->isLong()) {
                continue;
            }

            $doc = new DocPact($loan, $logger);

            if ($doc->check()) {
                unlink($doc->getFinalFile());
            }

            $doc->save();

            exec(sprintf('chown -R www-data:www-data %s', $doc->getFinalFile()));

            if ($doc->check()) {
                echo "Договор для займа {$loan->getId()} - перегенерирован.\n";
            }
        }
    }

    /**
     * Перегенерация существующих договоров по списку loanIds
     * Пример использования. Необходимо запускать от пользователя www-data.
     * sudo -u www-data ./yiic special reGenDocs
     *
     * @throws CException
     * @throws CantSaveFileException|Exception
     */
    public function actionReGenDocs(LoggerInterface $logger)
    {
        $loanIds = [
            172711,
            173811,
            170144,
            177984,
            173542,
            171123,
            173646,
            177592,
            175270,
            178077,
            169534,
            175984,
            178388,
            170094,
            171995,
            172735,
            174181,
            179064,
            179672,
            169776,
            172883,
            176951,
            169341,
            177058,
            174860,
        ];

        $criteria = new CDbCriteria();
        $criteria->addCondition('date IS NOT NULL AND cancel_date IS NULL');
        $criteria->addInCondition('loan_id', $loanIds);

        /** @var LoanProlongation[] $loans */
        $prolongs = LoanProlongation::model()->findAll($criteria);

        foreach ($prolongs as $prolong) {
            $docProlong = new DocProlong($prolong, $logger);
            try {
                $docProlong->save();
            } catch (Exception $e) {
                $errorMessage = 'Ошибка сохранения документа #' . $prolong->id . '. Описание: ' . $e->getMessage();
                $logger->error($errorMessage);
                echo $errorMessage . PHP_EOL;
            }

            if ($docProlong->check()) {
                echo "Пролонгация для займа $prolong->loan_id № $prolong->num - перегенерирована.\n";
            }
        }
    }

    /**
     * Перегенерация существующих заявлений на страховку
     * sudo -u www-data ./yiic special RegenerateStatementInsurance --begin='2017-06-01'
     *
     * @param $begin
     * @param null $end
     * @throws CException
     * @throws CantSaveFileException
     */
    public function actionRegenerateStatementInsurance($begin, LoggerInterface $logger, $end = null)
    {
        $beginDate = DateFmt::dateFromDB($begin);
        $beginDate->setTime(00, 00, 00);

        if (null === $end) {
            $endDate = new DateTime();
        } else {
            $endDate = DateFmt::dateFromDB($end);
        }

        $endDate->setTime(23, 59, 59);

        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition('issue_date', $beginDate->format(DateFmt::DT_DB), $endDate->format(DateFmt::DT_DB));
        $criteria->addInCondition('loan_status_id', [
            Status::ACTIVE,
            Status::PAY_OFF,
            Status::TRANSFER_OK,
        ]);

        /** @var LoanBase[] $loans */
        $loans = LoanBase::model()->findAll($criteria);
        foreach ($loans as $loan) {
            if (null === $loan->getApp()->insuranceLife || 0 === $loan->getApp()->getInsuranceAgree() || null === $loan->getApp()->getInsuranceRateId()) {
                continue;
            }

            $insuranceComponent = Insurance::createLifeIns(new FactoryInsuranceLife($loan->getApp()->insuranceLife, $logger));
            $docStatementInsurance = $insuranceComponent->getDocStatement($loan->getApp()->insuranceLife);
            $docPoliceInsurance = $insuranceComponent->getDocPolice($loan->getApp()->insuranceLife);

            foreach ([$docStatementInsurance, $docPoliceInsurance] as $key => $doc) {
                if (null === $doc) {
                    continue;
                }

                if (0 === $key) {
                    $docType = 'StatementInsurance';
                } else {
                    $docType = 'PoliceInsurance';
                }

                if ($doc->check()) {
                    unlink($doc->getFinalFile());
                } else {
                    echo $docType . " для займа {$loan->getId()} - не нужно генерировать.\n";
                    continue;
                }

                $doc->save();

                if ($doc->check()) {
                    echo $docType . " для займа {$loan->getId()} - перегенерирован.\n";
                    continue;
                } else {
                    echo $docType . " для займа {$loan->getId()} - неперегенерирован.\n";
                }
            }
        }
    }


    /**
     * Перегенерация существующих пролонгаций
     * sudo -u www-data ./yiic special reGenerateProlong --begin='2017-06-01'
     *
     * @param LoggerInterface $logger
     * @param string $begin
     * @param null $end
     * @throws CHttpException
     * @throws CantSaveFileException
     */
    public function actionReGenerateProlong(LoggerInterface $logger, $begin, $end = null)
    {
        $beginDate = DateFmt::dateFromDB($begin);
        $beginDate->setTime(00, 00, 00);

        if (null === $end) {
            $endDate = new DateTime();
        } else {
            $endDate = DateFmt::dateFromDB($end);
        }

        $endDate->setTime(23, 59, 59);

        $criteria = new CDbCriteria();
        $criteria->addCondition('date IS NOT NULL AND cancel_date IS NULL');
        $criteria->addBetweenCondition('date', $beginDate->format(DateFmt::DT_DB), $endDate->format(DateFmt::DT_DB));

        /** @var LoanProlongation[] $loans */
        $prolongs = LoanProlongation::model()->findAll($criteria);

        foreach ($prolongs as $prolong) {
            $docProlong = new DocProlong($prolong, $logger);
            $pathToFile = $docProlong->getFinalFile();
            try {
                $docProlong->save();
            } catch (Exception $e) {
                $logger->critical('Ошибка при сохранении пролонгации для займа с id: ' . $prolong->getLoan()->getId() . ' Сообщение: ' . $e->getMessage());
                throw new CHttpException(400, 'Сервис недоступен. Попробуйте еще раз');
            }

            if (is_file($pathToFile)) {
                echo "Пролонгация для займа $prolong->loan_id № $prolong->num - перегенерирована.\n";
            }
        }
    }

    /**
     * Перегенерация существующих дополнительных соглашений
     * sudo -u www-data ./yiic special reGenerateDocNoPenalty --begin='2018-10-23'
     *
     * @param LoggerInterface $logger
     * @param string $begin
     * @param null $end
     * @throws BaseException
     * @throws CException
     * @throws CantSaveFileException
     */
    public function actionReGenerateDocNoPenalty(LoggerInterface $logger, $begin, $end = null)
    {
        $beginDate = DateFmt::dateFromDB($begin);
        $beginDate->setTime(00, 00, 00);

        if (null === $end) {
            $endDate = new DateTime();
        } else {
            $endDate = DateFmt::dateFromDB($end);
        }

        $endDate->setTime(23, 59, 59);

        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition('issue_date', $beginDate->format(DateFmt::DT_DB), $endDate->format(DateFmt::DT_DB));
        $criteria->addInCondition('loan_status_id', [
            Status::ACTIVE,
            Status::PAY_OFF,
            Status::WAITING_TRANSFER,
            Status::TRANSFER_OK,
            Status::TRANSFER_FAIL,
        ]);

        $criteria->addCondition('interaction_type IS NOT NULL AND interaction_type != 0');

        /** @var LoanBase[] $loans */
        $loans = LoanBase::model()->findAll($criteria);

        foreach ($loans as $loan) {
            $doc = new DocNoPenalty($loan, $logger, (int)$loan->interaction_type, true);

            if ($doc->check()) {
                unlink($doc->getFinalFile());
            }

            $doc->save();

            if ($doc->check()) {
                echo "Дополнительное соглашение для займа {$loan->getId()} - перегенерирован.\n";
            }
        }
    }

    /**
     * Генерирует согласия для займа, кроме согласия на получения кредитной истории
     *
     * @param LoanBase $loan
     * @param LoggerInterface $logger
     * @return null|string
     * @throws BaseException
     * @throws CException
     * @throws CantSaveFileException
     */
    private function generateAgreements(LoanBase $loan, LoggerInterface $logger)
    {
        $errors = null;
        $agreementForLoans = DocAgreementNew::AGREEMENTS;

        foreach ($agreementForLoans as $agreement => $description) {
            $filename = sprintf('%s_%d.pdf', $agreement, $loan->getId());
            $oldPath = $loan->prepareDirForPact() . $filename;

            if (file_exists($oldPath)) {
                if (unlink($oldPath)) {
                    echo 'Удалили ' . $oldPath . "\n";
                }
            }

            $pathToFile = $loan->performAgreement($agreement, $logger);
            if (!file_exists($pathToFile)) {
                $errors .= 'Ошибка генерации ' . $agreement . ' для займа ' . $loan->getId() . "\n";
            }
        }

        return $errors;
    }

    /**
     * Перегенерация анкеты ПОД/ФТ
     * @param LoggerInterface $logger
     * @param $begin
     * @param null $end
     * @throws BaseException
     * @throws CantSaveFileException
     */
    public function actionRegeneratePodft(LoggerInterface $logger, $begin, $end = null)
    {
        /** @var DateTime $beginDate */
        $beginDate = DateFmt::dateFromDB($begin)->setTime(00, 00, 00);
        $endDate = new DateTime();
        if (null !== $end) {
            $endDate = DateFmt::dateFromDB($end)->setTime(23, 59, 59);
        }

        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition('creation_date', $beginDate->format(DateFmt::DT_DB), $endDate->format(DateFmt::DT_DB));

        /** @var CreditApplicationBase[] $apps */
        $apps = CreditApplicationBase::model()->findAll($criteria);
        foreach ($apps as $app) {
            if ((new DocFormPODFT($app, $logger))->save()) {
                echo 'Анкета ПОД/ФТ ' . $app->getId() . ' для клиента ' . $app->getClientId() . ' перегенерирована' . "\n";
            } else {
                echo 'Ошибка перегенерации анкеты ПОД/ФТ ' . $app->getId() . ' для клиента ' . $app->getClientId() . "\n";
            }
        }
    }


    /**
     * Метод генерирует документы по займу
     *
     * @param CreditApplicationBase $app
     * @param LoggerInterface $logger
     */
    private function generateDocs(CreditApplicationBase $app, LoggerInterface $logger)
    {
        $loan = $app->loan;

        try {
            $errors = null;
            $loanComponent = (new LoanFactoryByApp($app, $logger))->get();


            $pathToPact = $loanComponent->getPact($loan);
            if (!file_exists($pathToPact)) {
                $errors .= 'Ошибка формирования договора для займа ' . $loan->getId() . "\n";
            }

            $pathToAgreementBKI = $loan->performAgreement(DocAgreementNew::BKI, $logger);
            if (!file_exists($pathToAgreementBKI)) {
                $errors .= 'Ошибка формирования согласия БКИ для займа ' . $loan->getId() . "\n";
            }

            $pathToAgreementAcpt = $loan->performAgreement(DocAgreementNew::ACPT, $logger);
            if (!file_exists($pathToAgreementAcpt)) {
                $errors .= 'Ошибка формирования согласия на акцепт для займа ' . $loan->getId() . "\n";
            }

            if (null !== $loan->getApp() && $loan->getApp()->isArbitrationAgreement()) {
                $pathToArbitrationAgreement = $loan->performArbitrationAgreement($logger);
                if (!file_exists($pathToArbitrationAgreement)) {
                    $errors .= 'Ошибка формирования оферты на арбитражное соглашение для займа ' . $loan->getId() . "\n";
                }
            }

            $pathToForm = (new DocFormPODFT($app, $logger))->save();
            if (!file_exists($pathToForm)) {
                $errors .= 'Ошибка формирования анкеты ПОДФТ для заявки ' . $app->getId() . "\n";
            }

            if (null !== $errors) {
                throw new Exception($errors, 500);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        $app->addComment('Заявка одобрена.', DictionaryCommentType::APPLICATION_ACCEPTED, $loan->staff_id);
    }


    /**
     * Команда для нахождения ИНН по клиентам с активными займами
     *
     * @param LoanRepository $loanRepository
     * @param ClientBaseService $clientBaseService
     * @param TaxServiceClient $taxServiceClient
     *
     * @return void
     * @throws Exception
     */
    public function actionFindInnsForActiveLoans(
        LoanRepositoryInterface $loanRepository,
        ClientBaseService       $clientBaseService,
        TaxServiceClient        $taxServiceClient,
    ) {
        $limit = 500;
        $i = 0;
        $num = 0; // номер обработанного займа
        $loansNumber = $loanRepository->countAllForSearchingInn();
        do {
            $offset = $i * $limit;
            $loans = $loanRepository->findAllForSearchingInn(
                offset: $offset,
                limit:  $limit,
            );

            /** @var LoanBase $loan */
            foreach ($loans as $loan) {
                $needToSleep = true;
                $client = $loan->client;
                $dto = (new TaxServiceAdapter($client))->getDto();
                $inn = null;

                for ($getInnAttemptsNumber = 1; $getInnAttemptsNumber <= 3; $getInnAttemptsNumber++) {
                    try {
                        $inn = $taxServiceClient->getInn($dto);
                        echo 'ИНН по клиенту #' . $client->getId() . ' найден с ' . $getInnAttemptsNumber . ' попытки' . PHP_EOL;
                        break;
                    } catch (EmptyValueException $e) {
                        echo 'ИНН по клиенту #' . $client->getId() . ' не может быть найден: ' . $e->getMessage() . PHP_EOL;
                        $needToSleep = false;
                        break;
                    } catch (Throwable $e) {
                        echo $e->getMessage() . ' попытка ' . $getInnAttemptsNumber . PHP_EOL;
                    }
                    if (3 === $getInnAttemptsNumber) {
                        $clientBaseService->setNotFoundInn($client, ClientFlags::ACTIVE);
                        $needToSleep = false;
                        echo 'Установлен флаг inn_not_found для клиента #' . $client->getId() . PHP_EOL;
                    }
                    sleep(20);
                }

                if (null !== $inn) {
                    try {
                        $clientBaseService->setNotFoundInn(client: $client, value: ClientFlags::UNACTIVE);
                        $client->setInn(new Inn($inn));
                        $client->save(false, ['inn']);
                        echo 'ИНН клиента #' . $client->getId() . ' сохранен' . PHP_EOL;
                    } catch (Throwable $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }
                echo (++$num) . ' из ' . $loansNumber . ' обработано' . PHP_EOL;

                if ($needToSleep) {
                    sleep(30);
                }
            }
            $i++;
        } while (!empty($loans));

        echo 'Команда выполнена';
    }

    /**
     * Отправка для тестов 50 КИ за 20 и 50 КИ за 21 год Скибе
     *
     * @param EquifaxService $equifaxService
     * @param SkibaApiClient $skibaApiClient
     * @param PDO $pdo
     * @return void
     */
    public function actionSendCreditHistory(
        EquifaxService $equifaxService,
        SkibaApiClient $skibaApiClient,
        PDO            $pdo,
        $appId = null,
    ): void {
        $limit = 50;
        $i = 0;
        $failed = 0;

        $appQuery = ' AND `br`.`app_id` > 0 ';

        if (null !== $appId) {
            $appQuery = " AND `br`.`app_id` = :appId ";
        }

        do {

            $sql = 'SELECT DISTINCT `app_id`, `bki_id`, `br`.`client_id`, `br`.`id`
                FROM `bki_rating` `br` 
                JOIN `gfk_equifax`.`request` `chr` ON `br`.`bki_id` = `chr`.`id` 
                WHERE YEAR(`br`.`created_at`) >= :year 
                AND (`chr`.`status_text` = :statusFound
                OR `chr`.`status_text` = :statusFoundClient)
                AND `br`.`status` = :success' .
                $appQuery .
                ' AND `br`.`bki_id` > 0
                AND `br`.`client_id` > 0
                AND `br`.`send_to_skiba` = 0
                LIMIT ' . $limit;

            $params = [
                ':statusFound' => 'Клиент найден и у него есть кредитная история',
                ':statusFoundClient' => 'Заемщик найден',
                ':year' => 2020,
                ':success' => BKIRating::STATUS_SUCCESS,
            ];

            if (null !== $appId) {
                $params[':appId'] = $appId;
            }

            $query = $pdo->prepare($sql);
            $query->execute($params);

            $result = $query->fetchAll(PDO::FETCH_ASSOC);

            foreach ($result as $item) {
                $bki = BKIRating::model()->findByPk($item['id']);

                try {
                    $xml = $equifaxService->getCreditReportByBkiId($bki->getClientId(), $bki->getBkiId());
                    $equifaxXmlFactory = new EquifaxXmlFactory();

                    $creditHistoryV3 = $equifaxXmlFactory->makeOnly3Format($xml)->asXML();
                    $skibaApiClient->sendCreditHistory($creditHistoryV3, appId: $bki->getAppId(), reportVersion: ReportVersion::makeVersion3());

                    $bki->setSendToSkiba(1);
                    $bki->save(false, ['send_to_skiba']);

                    echo 'Отправлена КИ с bkiId #' . $bki->getBkiId() . ' и appId #' . $bki->getAppId() . PHP_EOL;
                } catch (Throwable $e) {
                    $failed++;

                    echo 'Произошла ошибка при отправке КИ с bkiId #' . $bki->getBkiId() .
                        ' и appId #' . $bki->getAppId() . ': ' . $e->getMessage() . PHP_EOL;
                }
            }
            $i++;

            echo 'Обработано ' . $limit * $i . ' КИ, из них неудачно отправилось ' . $failed . PHP_EOL;
        } while (!empty($result));

        echo 'Команда завершилась, отправлено ' . $limit * $i - $failed . ' файлов';
    }

    /**
     * Отправка для тестов 50 КИ за 20 и 50 КИ за 21 год Скибе
     *
     * @return void
     */
    public function actionSetSendFlagInBkiRating(): void
    {
        $bkiIds = [
            //2020
            560758, 560759, 560760, 560761, 560762, 560763, 560764, 560765, 560147, 560766, 560767, 560768, 560769,
            560770, 560771, 560772, 560773, 560774, 560775, 560776, 560777, 560778, 560779, 560780, 560781, 560782,
            560783, 560283, 560784, 560785, 560786, 560787, 560788, 559191, 560789, 560790, 554204, 560791, 560792,
            560307, 560793, 560794, 554407, 560795, 560796, 560797, 560798, 560799, 560800, 560801,
            //2021
            820809, 820810, 820811, 820812, 820813, 820814, 820815, 820816, 820817, 820818, 820819, 820820, 820821,
            820822, 820823, 820824, 820825, 820826, 820827, 820828, 820829, 820830, 820831, 820832, 820833, 820834,
            820835, 820836, 820837, 819230, 820838, 820839, 820840, 820841, 820842, 820843, 820844, 820845, 820846,
            820847, 820848, 820849, 820850, 820851, 820852, 820853, 820854, 820855, 820856, 820857,
        ];


        $criteria = new CDbCriteria();
        $criteria->addInCondition('`bki_id`', $bkiIds);
        $bkis = BKIRating::model()->findAll($criteria);
        foreach ($bkis as $bki) {
            $bki->setSendToSkiba(1);
            $bki->save();
        }
    }

    /**
     * Команда производит оплату займа из кошелька
     *
     * @param PaymentService $paymentService
     * @param int $loanId
     * @param LoanRepository $loanRepository
     * @param LoggerInterface $logger
     * @param LoanForStatService $loanForStatService
     * @return void
     * @throws CException
     */
    public function actionPayFromWalletSum(
        PaymentService     $paymentService,
        int                $loanId,
        LoanRepository     $loanRepository,
        LoggerInterface    $logger,
        LoanForStatService $loanForStatService
    ) {
        $loan = $loanRepository->findByPk($loanId);
        if (null === $loan) {
            echo 'Займа не существует: ' . $loanId . PHP_EOL;
            return;
        }

        $fullSum = $loan->getFullSum();
        $paymentService->payFromWalletSum(
            loan: $loan,
            sum: $fullSum,
            description: 'Оплата из кошелька',
            logger: $logger,
            loanForStatService: $loanForStatService
        );

        echo 'Вроде всё прошло успешно' . PHP_EOL;
    }

    /**
     * Устанавливает всем банкротам со staff_id != 20 (robot)
     * флаг confirmed и confirm_staff_id
     *
     * @param BankruptRepository $bankruptRepository
     * @return void
     */
    public function actionSetConfirmStatusAndConfirmStaffIdOfBankrupts(
        BankruptRepository $bankruptRepository
    ) {
        $i = 0;
        $limit = 500;
        do {
            $offset = $i * $limit;
            $bankrupts = $bankruptRepository->findChunkOfAll($offset, $limit);
            if (empty($bankrupts)) {
                break;
            }
            /** @var Bankrupt $bankrupt */
            foreach ($bankrupts as $bankrupt) {
                if (!$bankrupt->staff->isRobot()) {
                    $bankrupt->setConfirmStatus(new BankruptConfirmStatus(BankruptConfirmStatus::CONFIRMED));
                    $bankrupt->setConfirmStaffId($bankrupt->getStaffId());
                    if (!$bankrupt->save(true, ['confirm_status', 'confirm_staff_id'])) {
                        echo 'Error occurred: ' . $bankrupt->getError('confirm_status') . ' && ' . $bankrupt->getError('confirm_staff_id');
                        continue;
                    }

                    echo 'Update bankrupt with loanId #' . $bankrupt->getLoanId() . PHP_EOL;
                }
            }
            $i++;

            echo 'Processed ' . $i * $limit . ' rows' . PHP_EOL;
        } while (!empty($bankrupts));

        echo 'Command completed' . PHP_EOL;
    }

    public function actionTestNewPdn(
        CreditApplicationRepositoryInterface                                                                 $creditApplicationRepository,
        LoggerInterface                                                                                      $logger,
        \Glavfinans\Core\Entity\AverageIncomeQuarterByRegion\AverageIncomeQuarterByRegionRepositoryInterface $averageIncomeQuarterByRegionRepository,
        PDO                                                                                                  $pdo,
    ) {
        $sql = 'SELECT `app_id` FROM `bki_rating` WHERE DATE(`created_at`) > "2022-03-05" LIMIT 1000';
        $query = $pdo->prepare($sql);
        $query->execute();
        $data = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as $item) {
            $app = $creditApplicationRepository->findByPk($item['app_id']);
            try {
                $pdn = new \Glavfinans\Core\CreditHistory\CoefficientPDNFromCH($app, $logger, $averageIncomeQuarterByRegionRepository);
                echo 'ПДН по среднему доходу по региону: ' . $pdn->getPDN() . ' средняя зарплата по старому методу: ' .
                    $pdn->getSumAverageMonthlyIncome() . ' средняя зарплата по новому методу: ' . $pdn->getAverageSalary1() . PHP_EOL;
            } catch (Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
        echo 'The end' . PHP_EOL;
    }

    /**
     * Команда для генерации отчета 'Сравнение зарплаты клиента со среднедушевым доходом'
     *
     * @param ITaskPusher $taskPusher
     * @return void
     */
    public function actionGetCsvWithSalary(
        ITaskPusher $taskPusher,
    ) {
        $start = new DateTimeImmutable('01.01.2021');
        $start->setTime(0, 0, 0);
        $end = new DateTimeImmutable();
        $end->setTime(23, 59, 59);
        $task = new GenerateAverageSalary($start, $end);
        $taskPusher->push($task);
    }

    /**
     * Заполняет таблицу bankrupt_fed_request
     *
     * @param BankruptFedParseRepositoryInterface $bankruptFedParseRepository
     * @param BankruptFedRequestRepositoryInterface $bankruptFedRequestRepository
     * @param BankruptFedRequestResultFactory $bankruptFedRequestResultFactory
     * @param TransactionInterface $transaction
     * @return void
     */
    public function actionFillBankruptFedRequest(
        BankruptFedParseRepositoryInterface   $bankruptFedParseRepository,
        BankruptFedRequestRepositoryInterface $bankruptFedRequestRepository,
        BankruptFedRequestResultFactory       $bankruptFedRequestResultFactory,
        TransactionInterface                  $transaction,
    ) {
        $bankruptsFedParse = $bankruptFedParseRepository->findAll();
        /** @var BankruptFedParse $bankruptFedParse */
        foreach ($bankruptsFedParse as $bankruptFedParse) {
            $bankruptFedRequest = $bankruptFedRequestRepository->findByFedParseId($bankruptFedParse->getId());
            if (null !== $bankruptFedRequest) {
                continue;
            }

            $bankruptFedRequest = new BankruptFedRequest();
            $bankruptFedRequest->setCreatedAt($bankruptFedParse->getCreatedAt());
            $bankruptFedRequest->setUpdatedAt($bankruptFedParse->getUpdatedAt());
            $bankruptFedRequest->setClientId($bankruptFedParse->getClientId());
            $bankruptFedRequest->setBankruptFedParseId($bankruptFedParse->getId());
            $bankruptFedRequest->setBankruptId($bankruptFedParse->getBankruptId());
            $bankruptFedRequest->setResult($bankruptFedRequestResultFactory->makeBankrupt());
            $bankruptFedRequest->setBankruptFedParse($bankruptFedParse);

            $transaction->persist($bankruptFedRequest);
        }

        try {
            $transaction->run();
            echo PHP_EOL . 'Таблица bankrupt_fed_request успешно заполнена' . PHP_EOL . PHP_EOL;
        } catch (Throwable $e) {
            echo $e->getMessage();
        }
    }

    /**
     * Заполняет loan_id в таблицах bankrupt_fed_request и bankrupt_fed_parse
     *
     * @param BankruptFedRequestRepositoryInterface $bankruptFedRequestRepository
     * @param BankruptFedParseRepositoryInterface $bankruptFedParseRepository
     * @param BankruptRepositoryInterface $bankruptRepository
     * @param LoanRepositoryInterface $loanRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFillLoanIdInBankruptTbls(
        BankruptFedRequestRepositoryInterface $bankruptFedRequestRepository,
        BankruptFedParseRepositoryInterface   $bankruptFedParseRepository,
        BankruptRepositoryInterface           $bankruptRepository,
        LoanRepositoryInterface               $loanRepository,
        TransactionInterface                  $transaction,
    ) {
        $bankruptFedRequests = $bankruptFedRequestRepository->findAllWithoutLoanId();
        /** @var BankruptFedRequest $bankruptFedRequest */
        foreach ($bankruptFedRequests as $bankruptFedRequest) {
            $loan = $loanRepository->findLastActiveByClientIdAndDate(
                $bankruptFedRequest->getClientId(),
                $bankruptFedRequest->getUpdatedAt()
            );
            $bankrupt = $bankruptRepository->findByPK($bankruptFedRequest->getBankruptId());
            $bankruptFedRequest->setLoanId($bankrupt?->getLoanId() ?? $loan?->getId());
            $transaction->persist($bankruptFedRequest);
        }

        $bankruptFedParses = $bankruptFedParseRepository->findAllWithoutLoanId();
        /** @var BankruptFedRequest $bankruptFedRequest */
        foreach ($bankruptFedParses as $bankruptFedParse) {
            $bankrupt = $bankruptRepository->findByPK($bankruptFedParse->getBankruptId());
            $bankruptFedParse->setLoanId($bankrupt?->getLoanId());
            $transaction->persist($bankruptFedParse);
        }

        try {
            $transaction->run();
            echo "Таблицы bankrupt_fed_request и bankrupt_fed_parse обновлены\n";
        } catch (Throwable $e) {
            echo "Ошибка при обновлении таблиц bankrupt_fed_request и bankrupt_fed_parse\n";
        }
    }

    /**
     * Одноразовая команда для фикса id lead_provider_rate
     *
     * @param PDO $pdo
     * @return void
     */
    public function actionUpdateLeadProviderRate(PDO $pdo)
    {

        $sqls = [
            "UPDATE lead_provider_rate SET `date_start` = '2020-08-07 16:22:01', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 20 WHERE `id`=64;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-08-07 16:17:00', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 15 WHERE `id`=60;",
            "UPDATE lead_provider_rate SET `date_start` = '2021-08-18 16:46:38', `date_end` = '2022-03-11 11:40:52', `rate` = 1666, `rate_percent` = 0 WHERE `id`=77;",
            "UPDATE lead_provider_rate SET `date_start` = '2021-09-23 11:20:32', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 15 WHERE `id`=83;",
            "UPDATE lead_provider_rate SET `date_start` = '2021-10-26 09:42:03', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 20 WHERE `id`=91;",
            "UPDATE lead_provider_rate SET `date_start` = '2021-12-29 12:25:47', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 13 WHERE `id`=101;",
            "UPDATE lead_provider_rate SET `date_start` = '2021-09-03 10:19:12', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 15 WHERE `id`=82;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-11-11 11:18:37', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 10 WHERE `id`=72;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-08-07 16:19:00', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 21 WHERE `id`=62;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-08-07 15:13:00', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 20 WHERE `id`=57;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-03-06 09:24:24', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 20 WHERE `id`=49;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-06-05 15:20:58', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 10 WHERE `id`=48;",
            "UPDATE lead_provider_rate SET `date_start` = '2019-01-01 00:00:00', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 25 WHERE `id`=29;",
            "UPDATE lead_provider_rate SET `date_start` = '2019-01-01 00:00:00', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 17 WHERE `id`=33;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-07-07 14:31:11', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 20 WHERE `id`=51;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-02-18 14:36:17', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 13 WHERE `id`=104;",
        ];
        foreach ($sqls as $sql) {
            $query = $pdo->prepare($sql);
            $query->execute();
        }
    }

    /**
     * Команда для возвращения записей на 17 февраля
     *
     * @param PDO $pdo
     * @return void
     */
    public function actionJanuary2021LeadProviderRateComeback(PDO $pdo)
    {
        $sqls = [
            "UPDATE lead_provider_rate SET `date_start` = '2022-02-18 14:36:17', `date_end` = '2022-03-11 11:40:52', `rate` = 2000, `rate_percent` = 25 WHERE `id`=103;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-03-11 14:36:17', `date_end` = '2022-03-14 16:24:33', `rate` = 1500, `rate_percent` = 25 WHERE `id`=107;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-01-01 14:26:09', `date_end` = 'NULL', `rate` = 2000, `rate_percent` = 13 WHERE `id`=102;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-03-11 11:25:38', `date_end` = '2022-03-11 11:26:38', `rate` = 2000, `rate_percent` = 20 WHERE `id`=106;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-02-18 14:36:17', `date_end` = '2022-03-15 10:40:47', `rate` = 1500, `rate_percent` = 25 WHERE `id`=108;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-03-11 13:52:26', `date_end` = '2022-03-14 17:15:33', `rate` = 1500, `rate_percent` = 20 WHERE `id`=105;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-03-06 09:24:24', `date_end` = '2022-03-15 10:43:31', `rate` = 2000, `rate_percent` = 20 WHERE `id`=49;",
        ];
        foreach ($sqls as $sql) {
            $query = $pdo->prepare($sql);
            $query->execute();
        }
    }

    /**
     * Апдейт даты leadstech
     *
     * @param PDO $pdo
     * @return void
     */
    public function actionLeadstechFix(PDO $pdo)
    {
        $query = $pdo->prepare("UPDATE lead_provider_rate SET `date_start` = '2020-07-07 14:31:11', `date_end` = '2022-03-15 10:43:21', `rate` = 2000, `rate_percent` = 20 WHERE `id`=51;",);
        $query->execute();
    }

    /**
     * Команда для фикса расхождений с 11 марта
     *
     * @param PDO $pdo
     * @return void
     */
    public function actionLeadProviderRateMarch(PDO $pdo)
    {
        $sqls = [
            "UPDATE lead_provider_rate SET `date_start` = '2022-03-15 10:43:21', `date_end` = 'NULL', `rate` = 1500, `rate_percent` = 20 WHERE `id`=114;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-07-07 14:31:11', `date_end` = '2022-03-15 10:43:20', `rate` = 2000, `rate_percent` = 20 WHERE `id`=51;",

            "UPDATE lead_provider_rate SET `date_start` = '2022-03-15 11:25:47', `date_end` = 'NULL', `rate` = 1500, `rate_percent` = 25 WHERE `id`=119;",
            "UPDATE lead_provider_rate SET `date_start` = '2019-01-01 00:00:00', `date_end` = '2022-03-15 11:25:46', `rate` = 2000, `rate_percent` = 25 WHERE `id`=29;",

            "UPDATE lead_provider_rate SET `date_start` = '2022-03-15 11:50:16', `date_end` = 'NULL', `rate` = 1500, `rate_percent` = 21 WHERE `id`=122;",
            "UPDATE lead_provider_rate SET `date_start` = '2020-08-07 16:19:00', `date_end` = '2022-03-15 11:50:15', `rate` = 2000, `rate_percent` = 21 WHERE `id`=62;",

            "UPDATE lead_provider_rate SET `date_start` = '2021-09-03 10:19:12', `date_end` = '2022-03-15 11:26:25', `rate` = 2000, `rate_percent` = 20 WHERE `id`=82;",
            "UPDATE lead_provider_rate SET `date_start` = '2022-03-15 11:26:27', `date_end` = 'NULL', `rate` = 1500, `rate_percent` = 10 WHERE `id`=120;",

        ];
        foreach ($sqls as $sql) {
            $query = $pdo->prepare($sql);
            $query->execute();
        }
    }

    /**
     * @param ICreditHolidaysRepository $creditHolidaysRepository
     * @param LoanHistoryRepositoryInterface $loanHistoryRepository
     * @param LoanRepositoryInterface $loanRepository
     * @param DialerWorkflowService $dialerWorkflowService
     * @param LoanRestructuringRepositoryInterface $loanRestructuringRepository
     * @return void
     */
    public function actionCreditHolidayFix(
        ICreditHolidaysRepository            $creditHolidaysRepository,
        LoanHistoryRepositoryInterface       $loanHistoryRepository,
        LoanRepositoryInterface              $loanRepository,
        DialerWorkflowService                $dialerWorkflowService,
        LoanRestructuringRepositoryInterface $loanRestructuringRepository,
    ) {
        $creditHolidays = $creditHolidaysRepository->findAllActive();
        /** @var \Glavfinans\Core\Entity\CreditHolidays\CreditHolidays $creditHoliday */
        foreach ($creditHolidays as $creditHoliday) {
            $overdueDays = $loanHistoryRepository->getOverdueDaysOnDate($creditHoliday->getLoanId(), $creditHoliday->getDateStart());
            if ($overdueDays <= 0) {
                $loan = $loanRepository->findByPk($creditHoliday->getLoanId());
                if (null === $loan) {
                    echo 'не найден займ №' . $creditHoliday->getLoanId() . PHP_EOL;
                    break;
                }

                if ($loanRestructuringRepository->isRestructuring($loan->getId())) {
                    echo 'у займа №' . $creditHoliday->getLoanId() . ' оформлена реструктуризация' . PHP_EOL;
                    continue;
                }

                $lh = $loanHistoryRepository->getLhForLoanForStat($creditHoliday->getLoanId(), $creditHoliday->getDateStart());
                if (null !== $lh) {
                    $lh->end_date = ($lh->getEndDate()->modify('+' . DateFmt::calcDaysInclusive($creditHoliday->getDateStart(), $creditHoliday->getDateEnd()) . ' days'))->format(DateFmt::D_APP);
                    $lh->save(false, 'end_date');

                    $dialerWorkflowService->removeFromDialer($loan->getClientId());
                    $loan->detachFromSoftCollectorGroup();
                }

                echo 'Займ №' . $creditHoliday->getLoanId() . ' дата выдачи займа:' . $loan->getIssueDate()->format(DateFmt::DT_DB) . PHP_EOL;
            }
        }
    }

    /**
     * Команда обновляет
     * @return void
     */
    public function actionUpdateDestinationPayment(PaymentRepository $paymentRepository)
    {
        $paymentsWithErrorDestination = $paymentRepository->findWithErrorDestination();

        echo 'Ошибочных записей всего: ' . count($paymentsWithErrorDestination) . PHP_EOL;

        /** @var Payment $payment */
        foreach ($paymentsWithErrorDestination as $payment) {
            $destinationWithError = $payment->destination;
            $payment->destination = (str_replace(search: '|', replace: '', subject: $destinationWithError));

            echo 'Исправлена запись #' . $payment->getId() . ' с "' . $destinationWithError
                . '" на "' . $payment->destination . '"' . PHP_EOL;

            $payment->save(runValidation: false, attributes: ['destination']);
        }
    }

    public function actionRetroTest(
        CreditApplicationRepositoryInterface $creditApplicationRepository,
    ) {
        $limit = 500;
        $offset = 0;
        $dateBegin = DateFmt::dateFromDB('2020-12-24');
        $dateEnd = DateFmt::dateFromDB('2022-03-01');
        $now = (new DateTimeImmutable())->format('Ymd');
        $fileName = "3OM_SC_1_{$now}_0001";
        $path = __DIR__ . "/../../temp/$fileName.csv";
        $fh = fopen($path, 'w');
        if (!$fh) {
            throw new DomainException('Не удалось открыть файл на запись');
        }

        $allCount = $creditApplicationRepository->countPrimaryByCreatedAtInterval($dateBegin, $dateEnd);
        fputcsv($fh, [
            'num',
            'lastname',
            'firstname',
            'middlename',
            'birthday',
            'birthplace',
            'doctype',
            'docno',
            'docdate',
            'docplace',
            'pfno',
            'addr_reg_index',
            'addr_reg_total',
            'addr_fact_index',
            'addr_fact_total',
            'gender',
            'cred_type',
            'cred_currency',
            'cred_sum',
            'dateofreport',
            'reason',
            'reason_text',
            'consent',
            'consentdate',
            'consentenddate',
            'admcode_inform',
            'consent_owner',
            'private_inn',
            'phone_mobile',
            'phone_home',
            'phone_work',
        ], separator: ';');
        do {
            /** @var CreditApplicationBase[] $apps */
            $apps = $creditApplicationRepository->findPrimaryChunkByCreatedAtInterval($dateBegin, $dateEnd, $limit, $offset);
            foreach ($apps as $app) {
                $client = $app->client;
                $addrRegIndex = $client->getPassportIAddress()->getPostIndex();
                $addrFactIndex = $client->getHomeIAddress()->getPostIndex();
                if (empty($addrRegIndex)) {
                    $addrRegIndex = '000000';
                }

                if (empty($addrFactIndex)) {
                    $addrFactIndex = '000000';
                }

                $confirmDate = $app->getApprovementDate() ?? $app->getDeclineDate();
                if (null !== $confirmDate) {
                    $confirmDate = new DateTimeImmutable($confirmDate->format(DateFmt::DT_DB));
                }

                $smsConfirm = SmsConfirm::getLastSmsConfirmByClient($client, SmsConfirm::TYPE_BASE, $confirmDate);
                $consent = 0;
                $consentDate = null;
                $consentEndDate = null;
                if (null !== $smsConfirm) {
                    $consentDate = $smsConfirm->getConfirmDate();
                } elseif (null !== $app->bkiRating) {
                    $consentDate = $app->bkiRating->getCreatedAt();
                }

                if (null === $consentDate) {
                    continue;
                }

                $consent = 1;
                $dateOfReport = new DateTimeImmutable($app->getCreationDate()->format(DateFmt::DT_DB));
                $consentDate = new DateTimeImmutable($consentDate->format(DateFmt::DT_DB));
                if ($consentDate->modify('+6month') < $dateOfReport) {
                    continue;
                }

                $consentDate = match ($dateOfReport < $consentDate) {
                    true => $dateOfReport,
                    false => $consentDate,
                };
                $consentEndDate = $consentDate->modify('+6month');

                $firstName = $client->getFirstName();
                $lastName = $client->getLastName();
                $middleName = $client->getMiddleName();
                $birthDate = $client->getBirthDate();
                $birthPlace = $client->getBirthPlace();
                $docNo = $client->getPassportSeries() . $client->getPassportNumber();
                $docDate = $client->getPassportDate();
                $docPlace = $client->getIssuingAuthority();
                if (empty($firstName) || empty($lastName) || null === $birthDate ||
                    empty($birthPlace) || empty($docNo) || null === $docDate || empty($docPlace)) {
                    continue;
                }

                fwrite($fh, mb_convert_encoding(implode(';', [
                        $app->getId(), // num
                        $lastName, // lastname
                        $firstName, // firstname
                        $middleName, //middlename
                        $birthDate->format(DateFmt::D_APP_NEW), // birthday
                        $birthPlace, // birthplace
                        1, // doctype
                        $docNo, // docno
                        $docDate->format(DateFmt::D_APP_NEW), //docdate
                        $docPlace, // docplace
                        $client->getSnils()?->getValue(), // pfno
                        $addrRegIndex, // addr_reg_index
                        $client->getPassportIAddress()->getFull(), // addr_reg_total
                        $addrFactIndex, // addr_fact_index
                        $client->getHomeIAddress()->getFull(), // addr_fact_total
                        $client->getSex() + 1, // gender
                        19, // cred_type
                        'RUR', // cred_currency
                        $app->getRequestedSum(), // cred_sum
                        $app->getCreationDate()->format(DateFmt::D_APP_NEW), // dateofreport
                        1, // reason
                        '', // reason_text
                        $consent, // consent
                        $consentDate->format(DateFmt::D_APP_NEW), // consentdate
                        $consentEndDate->format(DateFmt::D_APP_NEW), // consentenddate
                        1, // admcode_inform
                        '', // consent_owner
                        $client->getInn()->getCode(), // private_inn
                        $client->getLoginPhone()->getDigits(), // phone_mobile
                        '', // phone_home
                        '', // phone_work
                    ]) . "\n", 'WINDOWS-1251'));
            }

            $offset += $limit;
            echo "$limit строк добавлено в файл. Всего добавлено в файл - $offset из $allCount\n";
        } while (!empty($apps));

        echo "\nКоманда retroTest завершена\n\n";
        fclose($fh);
    }

    /**
     * Определяет соответствие num в файле с ответом скоринга с id заявки
     *
     * @param CreditApplicationRepositoryInterface $creditApplicationRepository
     * @return void
     */
    public function actionMapNumToAppId(
        CreditApplicationRepositoryInterface $creditApplicationRepository,
    ) {
        $fileName = '3OM_SC_1_20220426_0001_out';
        $path = __DIR__ . "/../../temp/%s.csv";
        $fhFrom = fopen(sprintf($path, $fileName), 'r');
        if (!$fhFrom) {
            throw new DomainException('Не удалось открыть файл на чтение');
        }

        $fileName = 'result';
        $fhTo = fopen(sprintf($path, $fileName), 'w');
        if (!$fhTo) {
            throw new DomainException('Не удалось открыть файл на запись');
        }

        fputcsv($fhTo, [
            'num',
            'mapSuccess',
            'appId',
            'scor_card_id_list',
            'score_list',
        ], ';');

        $iterationNumber = 1;
        $csvKeys = [];
        $data = [];
        do {
            $dataRaw = substr(fgets($fhFrom, null), 0, -1);
            if (false === $dataRaw || '' === $dataRaw) {
                break;
            }

            $dataRaw = explode(';', mb_convert_encoding($dataRaw, 'UTF-8', 'CP1251'));
            if (1 === $iterationNumber++) {
                $csvKeys = $dataRaw;
                continue;
            }

            foreach ($dataRaw as $key => $value) {
                $data[$csvKeys[$key]] = $value;
            }

            $middleName = match ($data['middlename']) {
                '' => null,
                default => $data['middlename']
            };

            $creationDate = DateFmt::dateFromAppNew($data['dateofreport']);
            $apps = [];
            if (false !== $creationDate) {
                $apps = $creditApplicationRepository->findAllByClientFioAndCreationDate(
                    $data['lastname'],
                    $data['firstname'],
                    $middleName,
                    $creationDate
                );
            }

            $mapSuccess = 0;
            $content = 'Невозможно однозначно сопоставить num с appId. Найдено подходящих заявок ' . count($apps);
            if (1 === count($apps)) {
                $mapSuccess = 1;
                $content = $apps[0]->getId();
            }

            fputcsv($fhTo, [
                $data['num'],
                $mapSuccess,
                $content,
                $data['scor_card_id_list'],
                $data['score_list'],
            ], ';');

            if ($iterationNumber % 500 === 0) {
                echo "Обработано $iterationNumber записей из 433049\n";
            }

            if (433049 === $iterationNumber) {
                break;
            }
        } while (true);

        fclose($fhFrom);
        fclose($fhTo);

        echo "\n----Обработка завершена----\n\n";
    }

    /**
     * Фиксит ссылку в комментах по банкротам
     *
     * @param CommentRepositoryEntityInterface $commentRepositoryEntity
     * @param BankruptFedParseRepositoryInterface $bankruptFedParseRepository
     * @param ORMInterface $orm
     * @return void
     * @throws Throwable
     */
    public function actionFixBankruptComments(
        CommentRepositoryEntityInterface    $commentRepositoryEntity,
        BankruptFedParseRepositoryInterface $bankruptFedParseRepository,
        ORMInterface                        $orm,
    ): void {
        $offsetClientIds = 0;
        $limit = 100;
        $countComments = 0;
        do {
            $clientIds = $bankruptFedParseRepository->findAllClientIds($offsetClientIds, $limit);
            $offsetComments = 0;
            do if (!empty($clientIds)) {
                $entityManager = new Transaction($orm);
                $comments = $commentRepositoryEntity->findAllBankruptByClientIds($clientIds, $offsetComments, $limit);
                foreach ($comments as $comment) {
                    $bankruptFedParse = $bankruptFedParseRepository->findLastByClientId($comment->getClientId());
                    if (null === $bankruptFedParse) {
                        continue;
                    }

                    $rawData = json_decode(json: $bankruptFedParse->getData(), associative: true);
                    if (!array_key_exists('guid', $rawData)) {
                        continue;
                    }

                    $link = 'https://fedresurs.ru/person/' . $rawData['guid'];
                    $comment->setContent(
                        '_Клиент помечен как банкрот (ИНН).' . PHP_EOL .
                        "Ccылка на карточку в ЕФРСБ:" . PHP_EOL .
                        "<a href='$link' target='_blank'>$link</a>"
                    );
                    $entityManager->persist($comment);
                    $countComments++;
                }

                $entityManager->run();
                $orm->getHeap()->clean();

                $offsetComments += $limit;
                if (!empty($comments)) {
                    echo "Изменено $countComments комментариев" . PHP_EOL;
                }
            } while (!empty($comments));

            $offsetClientIds += $limit;

        } while (!empty($clientIds));

        echo PHP_EOL . "Все комментарии с банкротами обновлены" . PHP_EOL;
    }

    /**
     * Обновление ФИАС и ОКАТО у всех клиентов с активными займами
     *
     * @param ClientRepositoryInterface $clientRepository
     * @param DaDataClient $daDataClient
     * @param AddressService $addressService
     *
     * @return void
     */
    public function actionSetHomeAddressForActiveClient(
        ClientRepositoryInterface $clientRepository,
        DaDataClient              $daDataClient,
        AddressService            $addressService,
    ) {

        /** @var ClientBase[] $clients - Все клиенты, с активными займами, у которых необходимо добавить ОКАТО или адрес */
        $clients = $clientRepository->findAllActiveWithoutFullHomeAddress();

        $counters = [
            'successOld' => 0, // Успешно собранные по-старому
            'withoutOkato' => 0, // Клиенты с адресом, но без ОКАТО
            'withoutHomeAddress' => 0, // Клиенты без адреса
            'error' => 0, // Ошибки
        ];

        foreach ($clients as $client) {
            $countClient = array_sum(array: $counters);
            $baseMessage = "Запись $countClient | Клиент {$client->getId()} |";
            $homeAddress = $client->homeAddress;

            try {

                /** Вариант обработки если адрес есть, и нужно добавить окато */
                if (null !== $homeAddress) {
                    /** Если в адресе отсутствует ФИАС или ОКТМО - добавляем их в существующий адрес */
                    if (null === $homeAddress->getFias() || null === $homeAddress->getOkato()) {
                        $cleanAddress = $daDataClient->getCleanAddress(address: $homeAddress->getNormal());
                        AddressByDadata::incCntRequestsToday(); // Добавляем счётчик запросов

                        $normalAddress = $addressService->initAddress(address: $cleanAddress);
                        $client->setHomeAddressId(homeAddressId: $normalAddress->getId());

                        if ($homeAddress->getId() !== $normalAddress->getId()) {
                            $client->save(runValidation: false, attributes: ['home_address_id']);
                            $counters['withoutOkato']++;
                        }

                        echo "$baseMessage Добавили ФИАС и ОКАТО" . PHP_EOL;
                    }

                    continue;
                }

                /** Вариант обработки если адреса нет, и собираем его по-старому */
                $cleanAddress = $daDataClient->getCleanAddress(address: $client->getHomeIAddress()->getFull());
                AddressByDadata::incCntRequestsToday(); // Добавляем счётчик запросов

                $normalAddress = $addressService->initAddress(address: $cleanAddress);
                $client->setHomeAddressId(homeAddressId: $normalAddress->getId());

                $client->save(runValidation: false, attributes: ['home_address_id']);
                $counters['successOld']++;

            } catch (Throwable $e) {
                echo "$baseMessage Ошибка ({$e->getMessage()})" . PHP_EOL;
                $counters['error']++;
                continue;
            }
        }

        echo 'Всего обработали: ' . array_sum(array: $counters) . PHP_EOL;
        echo 'Клиенты с адресом, но без ОКАТО: ' . $counters['withoutOkato'] . PHP_EOL;
        echo 'Ошибочные: ' . $counters['error'] . PHP_EOL;
        echo 'Успешно собранные по старым адресам: ' . $counters['successOld'] . PHP_EOL;
    }

    /**
     * Решит все вопросики по атаке Ddos
     * @return void
     * @throws Exception
     */
    public function actionAttackOfDDos(LoanRestructuringRepositoryInterface $loanRestructuringRepository, LoggerInterface $logger)
    {
        $loanIds = [
            204268,
            213262,
            216214,
            217132,
            213231,
            208952,
            213350,
            208415,
            211075,
            210900,
            213248,
            201557,
            216593,
            194182,
            206072,
            201431,
            203907,
            209550,
            210892,
            213295,
            206643,
            201712,
            211097,
            211009,
            213237,
            214780,
            214376,
            213417,
            201745,
            209528,
            216416,
            211063,
            213443,
            216786,
            213397,
            216508,
            214485,
            210900,
            213424,
            213318,
            204593,
            214999,
            210968,
            208952,
            214782,
            216508,
            213328,
            206836,
            213250,
            216502,
            213257,
            213385,
            208731,
            213346,
            213318,
            198902,
            194182,
            208700,
            213295,
            214208,
            213424,
            216309,
            211097,
            215041,
            211052,
            215197,
            208811,
            213402,
            208389,
            212283,
            206735,
            213260,
            213764,
            205519,
            213750,
            205519,
            206735,
            213282,
            210789,
            206572,
            204242,
            201565,
        ];

        $dateBegin = DateFmt::dateFromDB('2022-05-23');
        $dateEnd = DateFmt::dateFromDB('2022-05-24');



        foreach ($loanIds as $loanId) {
            /** @var LoanBase $loan */
            $loan = LoanBase::model()->findByPk($loanId);

            if (null === $loan) {
                echo 'Не найден займ с id = ' . $loanId . PHP_EOL;
                continue;
            }

            // Если займ не активный и не выплачен, то пропускаем
            if (!$loan->getStatus()->isPayOff() && !$loan->getStatus()->isActive()) {
                echo 'Займ #' . $loanId . ' в статусе: ' . $loan->getStatus()->getTitle() . PHP_EOL;
                continue;
            }

            /** @var ClientBase $client */
            $client = $loan->client;

            if ($loan->getStatus()->isPayOff()) {
                $criteriaCharge = new CDbCriteria();
                $criteriaCharge->addCondition('loan_history_id IN (SELECT id FROM loan_history WHERE loan_id = :loanId) AND charge_date BETWEEN :dateStart AND :dateEnd AND type = :charge');
                $criteriaCharge->params = [
                    ':dateStart' => $dateBegin->format(DateFmt::D_DB),
                    ':dateEnd' => $dateEnd->format(DateFmt::D_DB),
                    ':charge' => Charge::CHARGE,
                    ':loanId' => $loan->getId(),
                ];
                $charges = Charge::model()->findAll($criteriaCharge);

                $sumInWallet = 0;
                foreach ($charges as $charge) {
                    $sumInWallet += $charge->getSum()->getRub();
                }

                if (0 === $client->getWalletSum()) {
                    $client->wallet_sum = $sumInWallet;
                    $client->save(false, ['wallet_sum']);
                } else {
                    echo 'У займа #' . $loanId . ' уже имеется бабло в кошельке, с сумой: ' . $client->getWalletSum() . PHP_EOL;
                }
            }
        }
    }


    public function actionDdosInWallet(LoggerInterface $logger)
    {
        $loanIds = [
            217132,
            213231,
            211075,
            210900,
            213248,
            206072,
            214376,
            210900,
            213318,
            214999,
            206836,
            211052,
            215197,
            208389,
            212283,
            209646,
            206735,
            213764,
            215679,
            213282,
            210789,
            204242,
            201565,
        ];

        foreach ($loanIds as $loanId) {
            $loan = LoanBase::model()->findByPk($loanId);
            if (null === $loan) {
                echo 'Не найден займ с id = ' . $loanId . PHP_EOL;
                continue;
            }

            /** @var ClientBase $client */
            $client = $loan->client;


            $date = new DateTimeImmutable('2022-05-23');
            $lh = $loan->getLHOnDate($date);

            if (null !== $lh) {
                $sumBody = $lh->sum;
                $percent = $lh->percent;
                $sumInWallet = (int)($sumBody * $percent) * 2;

                if (0 === $sumInWallet) {
                    echo 'id = ' . $loanId . ' SumLH = ' . $lh->sum . ' SumInWallet = ' . $sumInWallet . ' ПРОПУСКАЕМ!' . PHP_EOL;
                    continue;
                }

                echo 'id = ' . $loanId . ' SumLH = ' . $lh->sum . ' SumInWallet = ' . $sumInWallet . PHP_EOL;

                $client->wallet_sum = $sumInWallet;
                $client->save(false, ['wallet_sum']);

                $comment = new Comment();
                $comment->content = 'По займу № # ' . $loanId . ' перенесены проценты в сумме: ' . $sumInWallet . ' в кошелек из-за DDos атаки, за 23 и 24 мая';
                $comment->type_id = DictionaryCommentType::NOTE;
                $client->addComment($comment, $logger);


            } else {
                echo 'По займу не найден LH: ' . $loanId . PHP_EOL;
            }
        }
    }

    /**
     * Парсинг ЕФРСБ. Актуализация стадий банкротства по заданным client_id
     *
     * @param BankruptComponent $bankruptComponent
     * @return void
     * @throws Exception
     */
    public function actionUpdateBankruptStatusByClientId(BankruptComponent $bankruptComponent, ClientRepository $clientRepository): void
    {
        $clientIds = [135539, 168938, 219495, 167763, 223466, 229504, 243223, 227813, 258163, 313171, 345292, 347359, 658533, 214156, 297732,
            706987, 707293, 210100, 677743, 264807, 737524, 677220, 743445, 728679, 236093, 723156, 681060, 775988, 816955, 774230,
            247510, 772005, 832391, 820888, 798980, 848120, 801670, 338754, 738909, 842793, 730973, 872068, 860507, 931823, 938729,
            929542, 675107, 716087, 245151, 798117, 287101, 826012, 800646, 912179, 987958, 980821, 861166, 921272, 684354, 1065977,
            683073, 117982, 146619, 149489, 157533, 153609, 166740, 344335, 294776, 205125, 323420, 251697, 665755, 228061, 702111,
            99482, 268892, 750445, 729315, 769360, 664003, 320628, 864295, 787590, 877476, 880580, 880336, 655804, 822108, 907960,
            860496, 872405, 877031, 742692, 924132, 897949, 911140, 936610, 932182, 955415, 898546, 931329, 983082, 985660, 930650,
            996086, 1013583, 883721, 931709, 1040963, 1054300, 1025603, 985947, 881258, 1007692, 969397, 926542, 717137, 1112637,
            1053923, 958118, 1128231, 1129227, 784120, 843871, 1083096, 881170, 971637, 1157428, 991027, 1010591, 683984, 1146647,
            1191427, 1189169, 1058843, 1199465, 1203084, 892559, 306078, 1076188, 899855, 1003186, 1020440, 276628, 1221356, 951846,
            37282, 920264, 928000, 1106768, 712674, 701418, 338308, 921473, 837258, 1053405, 1233405, 873488, 860756, 1056720,
            834998, 1056608, 996801, 1204348, 1049834, 1053927, 1168647, 1210157, 662532, 778819, 657192, 1051381, 695402, 301396,
            887862, 1173314, 260452, 264850, 242993, 762644, 754144, 984041, 771960, 967638, 1142362, 1098787, 728456, 157354,
            264873, 792153, 706765, 896894, 927168, 925841, 908361, 939128, 976801, 1128249, 934029, 1146987, 825710, 833378,
            883288, 941837, 878977, 1016542, 1007390, 719890, 850597, 821717, 861086, 1211185, 692643, 140693, 142249, 165896,
            180891, 220638, 194729, 267715, 178107, 263690, 978496, 739868, 776754, 729414, 769079, 844130, 780285, 767010, 716244,
            755193, 664001, 689394, 689160, 321581, 696348, 686576, 685551, 664304, 318578, 243738, 274046, 334115, 256397, 230934,
            320696, 229155, 100240, 115734, 107047, 149422, 735622, 1025538, 1147025, 1203783, 1111219, 972763, 726988, 832029,
            832605, 834020, 932253, 943661, 887557, 933460, 699523, 987588, 1108568, 1003512, 1112811, 1207998, 1201069, 929254,
            1223636, 758357, 290640, 1066787, 320629, 1127479, 968802, 1115770, 998741, 1184506, 1224955, 835571, 963506, 1212517,
            977694, 1040624, 1178020, 1131154, 963852, 985448, 1156850, 1232235, 1016677, 989015, 933784, 1152949, 1221996, 1214164,
            1127211, 276264, 836009, 836399, 930654, 689154, 927633, 1112489, 680766, 1106459, 1059272, 1014831, 1117714, 1081904,
            858026, 1125326, 1202772, 928380, 1189971, 1217648, 932469, 974102, 944640, 1216383, 1196872, 1203055, 1104290, 1121633,
            744524, 1049174, 350515, 1013866, 1087766, 1237288, 1053329, 210326, 677842, 200682, 680160, 682978, 299847, 685449,
            354835, 692438, 785332, 835599, 853737, 799363, 961713, 973421, 800921, 809373, 943338, 982731, 1033110, 906012,
            1092137, 1012254, 866218, 926081, 1207495, 1265011, 1050591, 1145786, 1151767, 1105268, 939267, 934028, 1199743, 693878,
            963767, 241765, 1216252, 1058858, 1174189, 757570, 925324, 791929, 1041851, 1223686, 885018, 1200488, 832481, 1197077,
            674511, 355434, 731710, 817496, 894801, 819969, 790510, 1212363, 1193516, 1214152, 1026302, 926537, 1178624, 1176900,
            807341, 783678, 764353, 900321, 760129, 1007641, 1161050, 1109082, 1192621, 902264, 1125981, 1202132, 1193360, 1219864,
            1082496, 1200290, 1207119, 794606, 791996, 934006, 813529, 729471, 23184, 890588, 355678, 976561, 774342, 950836,
            953612, 726223, 1204981, 914612, 1201879, 1148024, 1015494, 1201903, 348521, 1211722, 1223896, 278652, 1209177, 1231403,
            1091021, 1200928, 1042762, 1215596, 1205948, 743642, 1208403, 855566, 912605, 922467, 1145908, 1247326, 230354, 359817,
            307258, 1199219, 1196151, 801590, 1153062, 1196646, 1146210, 1175386, 838262, 777702, 1236296, 1206975, 823202, 319419,
            697062, 1087151, 972372, 986409, 1210641, 1179055, 1250711, 729817, 248179, 979112, 1187315, 938595, 1137311, 1131181,
            1186055, 1105096, 938666, 1139776, 1208104, 1254042, 1199983, 1227788, 1020088, 1074315, 205921, 209157, 199986, 199662,
            154705, 230109, 227154, 214694, 238964, 744987, 344723, 752971, 260692, 745359, 250140, 843160, 353326, 917826, 940039,
            866102, 934569, 892973, 899667, 959468, 931159, 951409, 963078, 1118706, 810645, 954378, 843291, 1030126, 1063294,
            1135429, 1148869, 1060428, 1087584, 1106122, 1151278, 1138582, 951033, 1127450, 1106859, 1152919, 1142366, 790422,
            1232161, 1231910, 1217672, 1220926, 1233908, 1196894, 1235040, 1152734, 1223788, 1228601, 1206241, 1207705, 1170399,
            945759, 1204805, 1051348, 1232641, 1001504, 1209309, 1201133, 1178012, 1020445, 936050, 1205671, 1220432, 240811,
            215653, 141221, 116187, 256898, 237609, 242246, 752865, 964148, 945990, 964923, 949603, 670035, 967828, 906268, 899700,
            868094, 977623, 980134, 1095284, 1049137, 1132836, 898374, 1157285, 944673, 1067833, 1133485, 1161563, 1162023, 1036373,
            1162678, 1073898, 1162860, 870073, 1163038, 1042892, 1239069, 1224308, 1048712, 1177682, 1206986, 1254634, 1263193,
            1178535, 1070970, 809167, 1239586, 1247044, 259973, 1119173, 1088510, 801254, 1257719, 1199189, 1234818, 1234040,
            1209706, 953596, 841015, 1028731, 820731, 1221104, 1236403, 867897, 1007136, 1033487, 1196310, 898763, 1241809, 968794,
            1177267, 1164528, 1204669, 1008045, 1239899, 1201754, 175200, 1112517, 330107, 1169073, 260791, 1248398, 1258038,
            1237863, 1238783, 1205082, 1253878, 984874, 1169494, 1201037, 1161262, 1215749, 1264950, 1261418, 1226487, 1229977,
            1220323, 1106593, 798147, 1216288, 1205753, 848903, 1201170, 706480, 1124641, 1202302, 984042, 1166109, 1184613,
            1213958, 1170157, 1114711, 1053461, 1048421, 244501, 1220536, 692014, 1284339, 842025, 899861, 1201431, 1204854,
            1198320, 991631, 974840, 1127202, 1216679, 1204646, 1227258, 1226754, 1156137, 1233596, 1244776, 1215787, 1049039,
            1229791, 1224241, 200606, 1027608, 1219462, 1057996, 1060794, 1250539, 1276620, 1179818, 826774, 1214790, 1039742,
            1114642, 1262996, 1194412, 1289153, 658152, 1212486, 925441, 1153232, 1270482, 1231144, 861314, 915768, 862482, 877472,
            1212305, 1200136, 1207109, 1275156, 927379, 1212772, 1250735, 1169782, 1008188, 1019014, 1203473, 1151596, 1052561,
            1204899, 1243008, 817236, 1247890, 719929, 1127434, 1141226, 768001, 909343, 1159105, 1213484, 1237312, 1208198,
            1296433, 1210321, 1210059, 1207818, 1242599, 316877, 1212718, 1232995, 1010728, 332272, 1258537, 1232017, 1187555,
            1250098, 987914, 1220332, 1263154, 1253439, 1200746, 1203403, 1239859, 307731, 1260846, 49019, 1201434, 1016007,
            1141629, 981034, 1287705, 843161, 776984, 1250152, 1180169, 740489, 1096693, 1109706, 331307, 359153, 681333, 1229683,
            1138946, 250851, 691258, 680244, 1197993, 1233379, 841464, 1257202, 1292836, 1237234, 1019620, 1289388, 1245608, 194050,
            1240070, 1213625, 1274634, 953853, 321928, 1170135, 703794, 1066795, 1233621, 1205696, 1255836, 1214107, 774158,
            1195239, 1240425, 1232828, 1263357, 223978, 124586, 948171, 301579, 1231230, 1011940, 220096, 303040, 1155159, 988287,
            1160460, 1238113, 1038991, 1199453, 1214035, 1033713, 1278343, 1198311, 1187389, 923241, 1252604, 1252729, 1018985,
            1196815, 1269219, 964253, 1130680, 1300207, 1254421, 1066139, 767280, 1223550, 940561, 926836, 916395, 945170, 973158,
            1241868, 1242472, 1296404, 1251362, 1211564, 1184404, 1118861, 1161616, 1218131, 1242149, 1028621, 326885, 1245705,
            1190578, 908956, 1225758, 1189674, 1208364, 1193833, 1229909, 1263627, 1223448, 910638, 1280311, 179445, 201528, 260470,
            246070, 710182, 790360, 351172, 750075, 683198, 775886, 939051, 959170, 926557, 831842, 967730, 986370, 986760, 916164,
            949738, 1166967, 1168179, 953932, 941443, 1204632, 1243689, 1244293, 1244386, 1244548, 1244651, 1189731, 1245862,
            1244169, 1212232, 1247844, 773499, 839151, 1253424, 1284990, 1278683, 1302567, 1253167, 1203257, 1205324, 1218733,
            907433, 1256945, 1267298, 1244068, 1221063, 1168041, 1184668, 1277893, 1139599, 1151620, 1075747, 1214662, 1208095,
            977720, 1240349, 1210280, 1304983, 1257836, 251125, 1268353, 688763, 900284, 753283, 1224012, 677854, 1241614, 1169582,
            1226732, 1063770, 1289126, 1184748, 1265630, 1245794, 816996, 1174899, 1231551, 1236730, 1097389, 1332511, 916419,
            1199775, 1215467, 1222157, 1259332, 900462, 1279090, 926322, 1199369, 178792, 801850, 1219017, 869185, 1217495, 1299701,
            1271486, 1174825, 1072342, 842599, 1234480, 1198203, 1245845, 1222396, 1025128, 179139, 1245013, 1252417, 1230053,
            1107563, 1219876, 1266494, 882837, 944485, 943328, 1164760, 1061286, 1048671, 691463, 990537, 1206156, 1247018, 1247344,
            1261803, 1251209, 1299576, 1094921, 1262695, 1244284, 1281553, 1024508, 1118145, 1268182, 1256115, 960288, 1135598,
            1230915, 1257169, 1232665, 1252355, 1215761, 1302921, 1268711, 1325708, 986021, 1205611, 1193740, 283871, 1209151,
            1159376, 1198877, 1337483, 1060518, 1250511, 909452, 883917, 1229935, 1247551, 1262734, 1254503, 997546, 1234570,
            239040, 1341663, 1261514, 819165, 207703, 1036297, 1237935, 1228602, 1242344, 1298259, 1116961, 1251255, 1272404,
            1248504, 1201945, 700062, 966865, 1269442, 1322136, 1255823, 1189749, 1238592, 1270650, 339166, 1320842, 1235629,
            872549, 1267293, 1277346, 1309662, 1201809, 1296910, 1170749, 1262873, 1227576, 972067, 1211473, 714061, 1266567,
            1275096, 1158600, 712488, 848481, 1064316, 1067352, 1210688, 1306131, 779381, 1053984, 1210783, 1255375, 1352737,
            1200422, 1272497, 1194108, 1112262, 869526, 1155472, 1237761, 930408, 1166007, 277180, 940098, 873948, 858045, 1243393,
            1311241, 1259472, 851744, 1227654, 1264506, 784558, 726882, 317256, 1201305, 1251868, 280371, 1223840, 1137889, 1228441,
            348398, 779063, 1112164, 839930, 1039883, 1204592, 1200296, 952663, 216769, 1203893, 1204120, 1215540, 1306145, 315047,
            1335950, 1255419, 1273746, 1026252, 1329541, 684245, 1219350, 1327554, 741302, 1124239, 999897, 1277977, 1085013,
            1055650, 1138271, 1146077, 1233552, 1240383, 1242366, 1179519, 1210625, 1277816, 1227330, 1279247, 1228220, 1102670,
            955759, 1199778, 1347752, 1232809, 1217381, 1129850, 1294581, 1264189, 1204319, 1233512, 1261494, 1321649, 1215128,
            1291969, 1260515, 1289969, 1224724, 1300318, 1187588, 1251317, 897885, 1216792, 1148105, 736177, 1178107, 1245004,
            1023717, 1233950, 1124723, 1302417, 1302975, 1052858, 1248176, 963876, 1307539, 1321079, 1293920, 1220949, 1298628,
            1202413, 1274555, 799759, 849659, 1149576, 1161692, 160843, 854330, 1281377, 797049, 1243797, 1300102, 1267834, 1240721,
            1198643, 1280777, 271867, 1220185, 1256290, 885988, 1222222, 1315117, 1275614, 1015441, 1308325, 845267, 921200, 291915,
            1079137, 1293953, 1321245, 1147022, 711957, 669200, 1259792, 1199161, 870471, 894393, 1357245, 1316722, 1258443,
            1180666, 1214834, 981430, 703431, 1276489, 997254, 1318675, 909073, 1219355, 1306171, 1224051, 1025954, 1200872,
            1220026, 225814, 1311246, 1229007, 1218206, 1105272, 1210751, 960537, 1208085, 1224200, 1247267, 1300921, 1383407,
            1322573, 1219118, 1274487, 1326937, 728685, 1313509, 950989, 1225716, 1189923, 1273217, 1313549, 1176829, 1316758,
            1308067, 1250553, 990421, 1273700, 1252790, 1114233, 1213745, 252830, 200171, 224670, 964955, 1174730, 1043919, 1370595,
            1199411, 706736, 1269056, 1206285, 1143610, 357540, 1234429, 873637, 1296028, 1258041, 1118407, 871738, 1280027,
            1288565, 1270615, 1217459, 948814, 1263507, 1286395, 1305135, 1178469, 1353789, 1202553, 1260632, 1007427, 1244906,
            1299772, 1269018, 1277060, 1253470, 1304466, 700590, 1130409, 1302086, 1377048, 1375141, 1286092, 940997, 1210874,
            1308966, 1226436, 738884, 840420, 1224218, 1230702, 1323303, 1206501, 1325229, 881293, 1370315, 1281468, 1330154,
            1223416, 1228089, 946509, 1285495, 1279704, 1337446, 171360, 1226186, 1352020, 1362572, 958766, 328598, 984157,
            795756, 1093466, 1124865, 1170805, 1246084, 1240420, 1014214, 1235903, 1298611, 766671, 1162690, 1308064, 1308428,
            1374208, 1369796, 1278614, 1257560, 1314938, 1229612, 1308323, 1291135, 1191644, 1168507, 1234430, 1315849, 1336642,
            1065582, 925467, 1165528, 1221024, 1258626, 1238796, 1314691, 1160229, 1205906, 1293200, 1280440, 1374100, 964005,
            1312155, 1289555, 341100, 688355, 1245154, 1250533, 1303833, 1346459, 348235, 255263, 691649, 1115067, 832685, 1200184,
            1291216, 722403, 826857, 1080322, 1323626, 843989, 1315171, 1398572, 1278805, 1115370, 1138241, 1286407, 1074861,
            971184, 1203065, 1001509, 1297646, 1027354, 1292387, 1111549, 934553, 948689, 1305318, 1207196, 88459, 1077845, 95201,
            1331588, 1310670, 1374318, 1200531, 1259958, 1203367, 1333575, 1223394, 1331820, 1143286, 1313610, 790712, 1202673,
            674004, 1360643, 1220451, 846393, 1295868, 1304263, 1281329, 1304976, 1119034, 1247375, 1236534, 937585, 1264107,
            1348154, 1231907, 1218145, 1268945, 1269976, 1333639, 1161924, 822850, 1202785, 1319876, 1293803, 1214355, 1227708,
            1082007, 1120223, 1262008, 1276947, 1300978, 1111107, 1114882, 1116040, 1310725, 1036989, 1309765, 1208924, 924804,
            267219, 270199, 267550, 175343, 762939, 722366, 893496, 803950, 16444, 954781, 1004392, 1012595, 984395, 904952,
            1018130, 996892, 1016731, 1138918, 1047097, 1181080, 1018053, 908468, 1235549, 717963, 1205697, 1207492, 1171190,
            1204771, 1253152, 1253343, 1140158, 1215141, 825268, 1275060, 1374776, 1308712, 1309029, 1247925, 1232420, 1204993,
            1311658, 1285982, 1313093, 1282134, 1313807, 1313881, 1391110, 1298297, 1202837, 1382468, 1373174, 1386194, 1290683,
            877960, 937372, 1075517, 985363, 1281708, 1207432, 1284484, 1122087, 1224453, 1324564, 1246845, 693706, 177722,
            1265467, 1240851, 1226176, 1216886, 1299818, 1324139, 1090612, 1108181, 1245721, 1246862, 1242852, 255900, 1303565,
            1304364, 1238956, 1304794, 1271721, 1244536, 1223867, 1046637, 1175456, 1283795, 1351281, 1225969, 1242529, 1085018,
            1352381, 984227, 1092864, 1283728, 1295343, 1192027, 1223924, 1295487, 1224630, 1218197, 1297542, 1207382, 34044,
            1350626, 793510, 1098897, 1288162, 1255379, 1295375, 1288210, 1114542, 1276384, 1321402, 1372930, 977775, 819158,
            876368, 1347399, 267895, 1309866, 1313812, 1326826, 1360586, 1225717, 1246602, 1209807, 1355539, 1099818, 1261931,
            1236231, 1303034, 1158373, 1272686, 773799, 1321066, 1324774, 1216635, 1349787, 930854, 1287993, 1399496, 1296522,
            1201701, 1382042, 884385, 1206777, 1160242, 1204658, 1373713, 1138653, 919411, 1251236, 1378924, 1084622, 1060962,
            1130310, 1401077, 1369690, 1303307, 1252531, 1370065, 667823, 709170, 854555, 981614, 910893, 993410, 1214329, 1241733,
            1291941, 1379774, 1393876, 1317755, 1397385, 1367870, 1407294, 1308039, 1267547, 1312455, 1067014, 1019746, 1194899,
            1281510, 1436134, 1411235, 794997, 1386756, 1390291, 834170, 1256061, 1293807, 1223355, 1384772, 1301722, 1249036,
            1208024, 1302004, 1256292, 928190, 1177507, 1178025, 1172625, 1124769, 1008874, 1255123, 1207363, 1312160, 1314065,
            1314125, 1306054, 1144452, 1106577, 1235752, 1316511, 1366657, 1389301, 1401035, 1402043, 1403103, 1371738, 1403998,
            1075889, 1427724, 1339037, 1295169, 1005037, 329791, 771746, 907871, 1252517, 1231513, 1034000, 1256412, 1394614,
            1072412, 1200237, 1069145, 1211901, 1278786, 1227090, 1006279, 926347, 1060537, 1351628, 1207694, 1303210, 1338245,
            1227661, 1130739, 1234427, 1299745, 1362462, 768865, 964392, 1083808, 866107, 1201075, 1297389, 1324155, 1392860,
            1383088, 1394554, 1349308, 1300262, 1383954, 1230960, 1111908, 1005725, 1291096, 1205674, 866334, 935180, 1119784,
            1330688, 1328028, 1168419, 1228738, 1364744, 1283414, 1229385, 1046107, 1127152, 1263481, 890190, 1326142, 1255393,
            337723, 1300562, 1400091, 1421060, 1134855, 1191103, 1252907, 1432355, 1334720, 737092, 155278, 915857, 1241074,
            1289502, 1306979, 1385741, 1233350, 1325858, 1355061, 954772, 1235083, 1199446, 1131819, 242886, 1321339, 1044998,
            1234707, 1280299, 1376018, 1418905, 1236425, 1307773, 1307843, 1263427, 1249467, 1213285, 1427376, 970173, 1288729,
            1380863, 1313187, 1038276, 216794, 1236457, 1394412, 791315, 1381145, 953270, 1354368, 1348892, 1231046, 1411696,
            1275734, 969856, 337969, 1311550, 1351860, 1284425, 990541, 745040, 907002, 1353704, 1400204, 1403797, 1071817,
            1194663, 1387815, 1288537, 1193971, 1163246, 1213837, 1046407, 890877, 318326, 922513, 1450216, 1304748, 1374265,
            1251414, 1292646, 1199117, 281869, 1297866, 1426783, 844735, 1200202, 1208740, 1351642, 1335403, 1060354, 1274358,
            859201, 1236792, 337011, 1341466, 1246872, 1316928, 1333212, 1132597, 1414013, 1329676, 752257, 1315843, 1154965,
            1303693, 1222544, 987746, 1351807, 1343442, 1438549, 1379233, 1255936, 1276652, 1210151, 1373509, 1249689, 1253216,
            1230831, 1263160, 1310023, 1408504, 1450549, 959521, 1267644, 1222225, 1304478, 1230852, 1428136, 1335154, 1238653,
            1250824, 1351531, 1204117, 301497, 1282584, 1372764, 1445329, 1216778, 1018719, 1405309, 1211252, 1313513, 912560,
            1324454, 1465825, 1356852, 1364672, 1399534, 1288892, 1309197, 1250169, 1420764, 1420727, 1373673, 1280472, 1237171,
            1247891, 1431436, 1337524, 1240903, 1334495, 1228830, 1259354, 1344731, 1281185, 1418499, 1451129, 833637, 1284333,
            704297, 1293226, 1397550, 1290665, 804253, 1257381, 1235798, 1304287, 1357908, 258278, 1338559, 1292102, 1255039,
            1249255, 358140, 1462909, 1413352, 1378656, 1200900, 968663, 1297065, 1293693, 1241700, 1423915, 1306096, 1359825,
            1415136, 1269747, 1439127, 1314040, 1336073, 1320037, 1246390, 1436229, 1204580, 1311170, 50686, 1371610, 1377165,
            46290, 1318952, 1317134, 1254927, 1226965, 1313488, 1179877, 1384224, 1239781, 1237172, 1406241, 1337632, 1272895,
            303443, 983316, 1101938, 1272311, 1294932, 1390346, 1403383, 981776, 1420801, 1384173, 1222608, 1290467, 1396741,
            1243881, 1172447, 1232571, 1356320, 1434070, 1207622, 1322091, 1331879, 1388892, 1374827, 1423419, 1244957, 997687,
            1092390, 1258509, 709102, 1291112, 1303295, 1366889, 907138, 1219500, 1003726, 1353169, 844722, 1405489, 1368799,
            1301015, 1313866, 1094058, 1241056, 1407676, 886346, 1479289, 1205197, 1430063, 1353433, 1230655, 1392710, 1312899,
            1276059, 1392642, 1419999, 1246775, 1351025, 1257617, 1462240, 1306995, 1428211, 1220661, 1393313, 1414670, 850193,
            1409759, 1400602, 1396712, 1315382, 1178219, 1260074, 1247056, 1275011, 1274746, 279078, 1162820, 1188661, 1334387,
            1417288, 1228078, 1429926, 940491, 1448659, 1241025, 1300031, 1439689, 1438932, 1200999, 1310342, 1401564, 1298626,
            947347, 1426534, 1396514, 1252677, 1224978, 1162233, 1366146, 1248573, 1496718, 1180257, 227139, 224616, 267408, 272750,
            259904, 255953, 258066, 325013, 326903, 286950, 300611, 177284, 351045, 352501, 355856, 357328, 288971, 164781, 658341,
            660173, 245768, 257736, 659294, 299550, 680232, 672182, 148856, 246036, 344595, 699374, 701363, 691317, 705394, 667174,
            682901, 707738, 705951, 718004, 328152, 727198, 304223, 721644, 155503, 731594, 717036, 221894, 705220, 284627, 670099,
            217759, 701303, 749378, 759317, 760983, 750588, 763152, 802798, 348845, 822601, 775416, 832822, 821061, 783893, 797563,
            728973, 839878, 840293, 797665, 825244, 816659, 746661, 853835, 843703, 806558, 819954, 729559, 860910, 840894, 757354,
            811560, 821195, 766644, 724396, 873715, 877104, 879377, 767507, 853260, 881664, 851720, 881681, 891822, 876750, 892693,
            834606, 323989, 910866, 907766, 906586, 906734, 867691, 916389, 865569, 900346, 918838, 869514, 920296, 684659, 753034,
            738468, 900154, 916620, 921246, 891550, 929740, 778411, 935202, 895474, 940838, 844843, 911814, 939078, 962567, 954771,
            878734, 764426, 713690, 890370, 920420, 1006366, 1012634, 991427, 894117, 1008939, 802952, 848386, 1028747, 996720,
            1011352, 984301, 906487, 1043805, 997595, 1046886, 911640, 1052780, 1036427, 195606, 1012099, 804978, 1003494, 1059271,
            909818, 763554, 1067789, 674969, 809825, 1070004, 1071188, 825231, 940803, 964137, 885093, 945559, 1022582, 751092,
            723581, 239229, 967477, 1087159, 993408, 950758, 953517, 1077398, 1105100, 1061201, 689826, 987399, 1063221, 915306,
            1118565, 967581, 326811, 1120785, 983025, 721493, 1013139, 1014048, 1099377, 1128425, 1075797, 1104762, 999424, 786971,
            1137070, 1137843, 1137851, 1139799, 1140130, 1013640, 1040543, 1122607, 931727, 1097363, 955750, 1150590, 836228,
            997684, 725837, 776218, 979140, 1200576, 1200810, 1199134, 1199745, 1198027, 1198519, 1202815, 1195693, 985457, 1197015,
            1199868, 1205648, 1197892, 1156120, 1207248, 1207333, 936196, 1207986, 1208281, 1116053, 1209692, 1210274, 1210378,
            797803, 1201269, 1205096, 1211108, 940864, 1211739, 1206278, 1213260, 1207134, 1206535, 1214248, 1214706, 1212160,
            1214931, 1099793, 1215732, 1216088, 1200858, 1216640, 1205211, 1137811, 1190575, 1041795, 1212184, 1047399, 1219250,
            1190740, 1220996, 1221239, 1209412, 1222410, 1223057, 1207172, 1224506, 1227830, 1228157, 1228181, 1228234, 1228480,
            903618, 1228284, 1208617, 1230648, 1094746, 1233033, 1223791, 1234081, 1234366, 1203765, 1235474, 1235545, 1236014,
            1236531, 1236760, 1222411, 1238405, 1225581, 1240968, 1224057, 1209307, 1241414, 1226614, 1247431, 1176348, 91752,
            1249570, 1224543, 1254083, 1238659, 1180210, 1200478, 1186151, 1227277, 1249580, 1257958, 1258171, 1258850, 1258946,
            962510, 1250877, 1260340, 1260526, 1202937, 1227635, 1229009, 1261918, 1262063, 1263078, 1259031, 1201038, 1263303,
            1248939, 1263839, 1246804, 737948, 1238302, 1257184, 1239220, 844013, 1233046, 1234563, 1014056, 1267171, 1252845,
            941655, 1253126, 1226690, 1253226, 1270301, 1214543, 1237480, 1271195, 1037524, 1271374, 1209323, 1239672, 1261367,
            1142801, 1274542, 1237932, 1234812, 1276074, 1253389, 1263129, 1247396, 1211853, 1073568, 1211869, 1228180, 1198636,
            1133480, 1281238, 1226848, 1252144, 1284317, 1247285, 1252558, 1169830, 1218689, 1286394, 947084, 1271383, 907581,
            987735, 1267576, 1290392, 1267239, 1207781, 1178979, 1252592, 1265055, 1257914, 1292230, 1264177, 1293158, 1275794,
            1285260, 1295539, 1260988, 1295652, 1008470, 1261210, 1296745, 1296886, 1297369, 1298148, 1283671, 1283776, 1268171,
            1263522, 1279063, 1299784, 1262114, 1247468, 1300219, 1301317, 185934, 1296268, 1072869, 1259056, 1304620, 1305117,
            1305200, 1305889, 1245427, 1023629, 1261444, 1150697, 1308518, 1294687, 1309644, 1293272, 1299953, 1241749, 1305817,
            1271500, 1317653, 1317664, 1276965, 1320391, 1282955, 1300402, 1322117, 1322088, 1322478, 1307557, 1311555, 1310987,
            1301443, 1158224, 1220247, 1295295, 1319373, 1303745, 1209293, 1304611, 271175, 1327936, 1323796, 1329523, 1290459,
            1329559, 1277670, 1252983, 1326787, 1269913, 1331193, 1271010, 1108400, 1155210, 1310243, 1312140, 1278883, 1333090,
            1295192, 1333291, 1333662, 1333791, 1300133, 1334458, 1334893, 1335745, 1287028, 1312660, 1314238, 1337751, 1244422,
            1065292, 1206740, 1340869, 1323125, 986011, 1114435, 1156124, 1287604, 1323311, 1344647, 1333442, 1344740, 1297935,
            1253886, 1241894, 225891, 1346766, 1346993, 1333182, 1309309, 1287675, 1072359, 1267754, 1163696, 1350440, 982100,
            1331084, 1255385, 1332302, 1246570, 1353526, 1243049, 1332338, 1313582, 1262760, 1204655, 1356526, 1354415, 1346466,
            1358021, 1323128, 1310889, 1358751, 1355292, 1359619, 1332375, 1359743, 1328326, 1265651, 1360776, 1360069, 1320446,
            1305810, 1357072, 1359429, 1318712, 1362853, 1363197, 1364502, 1348068, 1322701, 1255237, 1208479, 1255424, 1310953,
            1354972, 1319577, 1368962, 1075532, 1212261, 1336101, 1290141, 1284725, 1370920, 1352727, 1371147, 1339906, 1303380,
            1375282, 1198997, 1376460, 1270252, 1259848, 1379187, 1329990, 1281005, 1290341, 1359864, 1356460, 1381904, 1274828,
            1385247, 1298266, 1387809, 1268837, 1290976, 1321929, 1357783, 1233976, 1315262, 1013710, 1333114, 1391563, 774073,
            1284505, 1338481, 1392176, 1374341, 1358514, 1115033, 1231610, 1337261, 1404271, 1223987, 1150795, 1356839, 1389707,
            1406847, 1226479, 1398929, 1344319, 1224922, 1221373, 1258785, 1413291, 1113894, 1415731, 1382343, 1416782, 1227740,
            1394573, 1417672, 1418292, 1351759, 1419388, 1235395, 1381688, 1329692, 1270571, 1360072, 1413907, 729941, 1426949,
            1427132, 1377545, 1177074, 1428383, 1342963, 1429252, 1292949, 1398025, 1430792, 1432661, 1387157, 1232820, 1434947,
            724540, 1274214, 1411004, 1263285, 1438478, 1309509, 1414906, 1221649, 1379083, 1400584, 1441013, 1257676, 1442274,
            1442410, 1373853, 1413368, 1408742, 1342775, 1210593, 1447529, 1439143, 1410932, 1450927, 1452364, 1453686, 1454433,
            1309879, 1456564, 1407939, 1369753, 1462378, 1468029, 1223006, 1168584, 1480351, 1465190, 1482545, 1485263, 1488354,
            1344279, 1494259, 1405179, 1499901, 1520742, 1560862, 1242309, 857324, 1392142, 1353566, 1445181, 1319575, 337271,
            736686, 886703, 930080, 1108505, 1161129, 1089648, 760975, 1190247, 48581, 1026338, 1254138, 1259003, 1258081, 1270621,
            1271581, 888151, 1268411, 1200677, 1281370, 1307975, 1309235, 1283535, 1230010, 30557, 1351110, 1353753, 1314531,
            1371585, 1371901, 1373821, 1373877, 1387632, 1256993, 1305672, 1282664, 1252685, 1390523, 689858, 1387153, 1205690,
            1410033, 1407861, 1283890, 1419764, 1444192, 1315256, 1452334, 1454944, 1380772, 1424003, 1301127, 1463091, 1469395,
            1470206, 1471393, 1461033, 1487012, 1469692, 1501716, 1479864, 346659, 1413800, 1410997, 1436695, 1462269, 734903,
            1323703, 1511521, 1189332, 813035, 1300359, 1316290, 974472, 1100130, 1233692, 1522909, 1474268, 1421559, 1505477,
            980543, 1203618, 253754, 1426854, 1403291, 282378, 1358426, 1360347, 1164784, 711140, 1435805, 1080436, 677386, 1348969,
            1147834, 273513, 1450752, 1478647, 1434311, 950655, 1251665, 1115957, 1244224, 1568060, 1429342, 1475879, 1243203,
            1435667, 1395719, 1446426, 1397847, 1249827, 1484121, 1310288, 1443974, 1451048, 1082439, 1261835, 1054599, 1305783,
            1476846, 1468513, 1472237, 1454962, 1256085, 1394073, 1223643, 814294, 1348759, 877506, 877056, 1211746, 686903,
            1491651, 171876, 118531, 215935, 290722, 1232932, 1408111, 668535, 1334732, 1370737, 272912, 1398992, 1303799, 1368319,
            1214371, 1285178, 1318054, 1302058, 1316221, 1217758, 1490297, 1437830, 1335293, 1352238, 253985, 1264647, 1491844,
            1483042, 1305997, 1482122, 1388810, 1460834, 1472050, 289575, 240116, 300186, 318778, 311282, 325421, 249888, 314087,
            304720, 343238, 298181, 353415, 359992, 676366, 343103, 676345, 340257, 319137, 692912, 684936, 742955, 758068, 663829,
            298715, 775443, 736855, 662987, 772420, 744176, 797771, 823781, 729710, 838819, 843745, 344001, 807251, 853104, 810069,
            693580, 782503, 867762, 894850, 723248, 900994, 915963, 897112, 884296, 912817, 922773, 907619, 866485, 929848, 931316,
            934506, 895930, 923044, 948026, 949342, 938565, 993215, 960459, 996260, 1018352, 886531, 726450, 975602, 963592, 912587,
            333323, 1036561, 1044220, 1017394, 890081, 989745, 901923, 1070496, 1069224, 899271, 1082040, 919925, 1058048, 1023972,
            1051580, 247633, 206971, 1108791, 981239, 711562, 930244, 1078803, 989284, 244208, 1134198, 1105069, 1112800, 951622,
            1112680, 1012445, 1090455, 1024696, 1151943, 1165725, 1172292, 1048502, 1159302, 1045577, 1148310, 1010120, 1195936,
            1084880, 1193327, 1201569, 1100546, 1203529, 1177000, 1202950, 1202382, 1202836, 1207354, 1209112, 1211577, 1202859,
            1211808, 717935, 1178164, 1164609, 1215659, 903638, 1034228, 1107074, 1220080, 1220114, 1201781, 1002758, 1198905,
            1204636, 1226954, 1215795, 1211880, 1212325, 955456, 1060587, 1235098, 1225528, 1214598, 1216468, 1213070, 1242038,
            1202237, 1233526, 1239128, 1021340, 973223, 1256152, 1255915, 1192477, 1256818, 1259251, 1243564, 1262856, 1241161,
            1198715, 1221444, 1031176, 1270295, 1248380, 1243698, 1252496, 1244868, 1245916, 1248148, 955489, 1278074, 1258686,
            1251272, 1281677, 932106, 1287058, 1244599, 239280, 1291314, 343867, 1271806, 1292382, 196452, 1279583, 1252903,
            1295271, 1263301, 1239809, 1290527, 807208, 1257788, 873303, 1216622, 1279215, 1302583, 1304541, 1297066, 1306380,
            1292394, 1237163, 1282654, 1311618, 931033, 1313880, 1202973, 1315357, 1050024, 1472849, 1317744, 1318696, 1319087,
            1319835, 1304280, 1290047, 1304087, 1280393, 1327312, 1327792, 1328274, 1328403, 1328910, 1315869, 1279093, 1275007,
            1214313, 1332739, 1331564, 921049, 1380380, 1357637, 1459470, 1353252, 1354307, 1264100, 1361121, 1288777, 1214691,
            1255940, 1283476, 1327880, 1484815, 1315896, 1316405, 1304046, 1267225, 1179293, 1269082, 1266182, 1350114, 1350210,
            1350292, 1307242, 1317879, 1146342, 1344060, 1109683, 1232929, 1399628, 1326983, 1354032, 1327004, 1355091, 1333451,
            1342975, 1312398, 1360557, 1299441, 1273963, 1340860, 1304474, 1022358, 1365269, 1365649, 1276495, 1366570, 1345885,
            1347444, 1355337, 1371780, 1250800, 1213194, 1349563, 1379142, 1382208, 1383075, 1383714, 1383801, 1200566, 1278292,
            1345965, 1351060, 1390880, 1354398, 1181989, 1230672, 1296812, 1331502, 1394964, 1251942, 1354177, 1396270, 1340219,
            1396482, 1329769, 145777, 1381552, 1399026, 1399059, 1223234, 1399920, 1233014, 1274375, 690354, 1366242, 1404167,
            1403952, 1404745, 1193932, 1386440, 1166570, 1383707, 1409248, 1410250, 1411561, 1412590, 1356086, 1269881, 1332631,
            1350381, 1254008, 1371371, 1412934, 1212917, 1418574, 1387774, 1307354, 1419311, 1374425, 1413461, 1420743, 1420866,
            1375567, 1359199, 1425855, 1426126, 1429386, 1348323, 1432028, 1249663, 1397788, 1172107, 1433271, 1386757, 1434449,
            1393067, 1002680, 1422092, 1436055, 1402101, 1272647, 1320552, 1237610, 1437865, 1384913, 1438086, 1199686, 1439081,
            1440399, 1335986, 1440537, 1272843, 1441120, 1441357, 1298663, 1427043, 1442339, 1385466, 1404141, 1443352, 1419025,
            1400589, 1420124, 1393848, 1362271, 1430656, 1434132, 1371661, 1240846, 1388450, 1452383, 1453075, 1435505, 1436073,
            1433098, 1313982, 1456955, 1484943, 1380690, 1217118, 1396534, 1458985, 1435586, 1473522, 1300041, 1461076, 1462332,
            1463099, 1463666, 1325057, 1172470, 1172690, 1345398, 1448383, 1338900, 1465634, 1465789, 929489, 1412492, 1466996,
            1345110, 1349282, 1426298, 1441793, 1468710, 1458601, 1425560, 1461284, 1365262, 1418238, 1436117, 1283002, 1475987,
            1475533, 1407926, 1422785, 1279069, 1479980, 1417304, 1480962, 1481805, 1482216, 1314059, 1080862, 1485982, 1075925,
            1486758, 1437215, 1482939, 1488145, 1452782, 1480004, 1404356, 1489969, 1493675, 1421910, 1491074, 1497251, 1285189,
            1431276, 1497714, 1449272, 1497926, 740821, 1498012, 1148674, 1469588, 1500198, 1477900, 1468829, 1493400, 1377093,
            1284874, 1353299, 1412703, 1405459, 1507031, 1307575, 1492730, 1510163, 1510103, 1511803, 1494606, 1513677, 1515063,
            1495310, 219464, 1524512, 1472619, 1529748, 1515850, 1542504, 1525409, 1534124, 1576953, 791076, 1592222, 1411910,
            1451490, 1322926, 1521994, 1076257, 1511598, 672866, 301024, 782651, 706165, 1294954, 980805, 1083366, 1336167, 1465328,
            1076335, 1507330, 1203990, 1106203, 1209874, 1216808, 1455122, 1183088, 1231359, 1202069, 1281520, 1237155, 1267863,
            1303484, 1268587, 1306502, 1162875, 1289410, 1305002, 1203323, 1212833, 279125, 1338031, 1309135, 1333284, 1270408,
            1339961, 1334905, 1283784, 1340619, 1363629, 1341709, 1238741, 1316239, 1378281, 1392351, 1380105, 1356334, 1379731,
            1323925, 1261960, 1397541, 1401973, 1405142, 1261253, 1299810, 961434, 1409082, 1397797, 1213507, 1344921, 1389298,
            1273478, 1423010, 1198128, 1432342, 1417022, 1435921, 1438525, 1261520, 1440207, 1360302, 818230, 1445054, 1446807,
            1091546, 1381645, 1390673, 1135281, 1156376, 1533518, 1453218, 1456069, 1457030, 1392033, 1458241, 1458377, 1460040,
            1314088, 1462913, 1463506, 1464370, 1464976, 1389569, 1452360, 969431, 1461644, 869982, 1476629, 1477284, 1478224,
            1480196, 1470410, 1481493, 1481888, 1484496, 1487414, 1488211, 1481291, 1497672, 1498045, 1297538, 1503232, 1504080,
            1354963, 1493283, 1505652, 1450698, 1516785, 1524862, 1380159, 1247363, 1499659, 1444671, 1259924, 1437281, 1262782,
            1470421, 1407616, 1464105, 1478783, 1211694, 1307019, 1612851, 1286126, 1467715, 339721, 1345640, 1508511, 1291667,
            1380277, 1519828, 1428698, 1466827, 976770, 1567298, 1503359, 1502951, 1461541, 1518628, 1504496, 1500705, 1500645,
            1470370, 1412620, 805046, 1474112, 1468044, 124897, 1486892, 1443727, 1469570, 1474527, 1359533, 1468815, 1502166,
            1195853, 1273009, 1210748, 1500659, 1520746, 1427550, 1483286, 1266904, 1402283, 1234743, 1226440, 1175776, 1353062,
            1239206, 1456034, 1488060, 1290347, 1491218, 1254510, 227278, 228086, 210357, 293476, 110123, 339688, 275005, 1487345,
            325900, 740663, 736621, 789010, 881726, 896903, 922365, 924498, 905914, 1467606, 1228215, 1527195, 979725, 666286,
            1020020, 1245158, 1318664, 1463413, 1478249, 1395092, 1211824, 1450215, 1025174, 1483136, 1497517, 974647, 1547211,
            1300413, 1493668, 1499662, 1235391, 1436373, 1401793, 1491278, 1452864, 1475204, 1468399, 1297442, 1136691, 1213978,
            1540234, 1463496, 1253084, 1394783, 1497977, 1359565, 1569334, 1265569, 1207219, 1505798, 1551137, 1283012, 1547335,
            1530722, 1492030, 1480776, 1269915, 1397694, 1471173, 1542703, 1452477, 1429594, 837744, 1386987, 1301751, 1339676,
            1435915, 1507972, 1455458, 1434675, 1487556, 1457663, 1265349, 731836, 1260125, 1523395, 1402458, 1309864, 1312766,
            1540046, 1516153, 1521126, 1514999, 1311536, 1529269, 1521896, 1263037, 1519455, 1440240, 1547923, 1490658, 1463634,
            1618718, 1549274, 1417441, 1481836, 1376763, 1518499, 1505116, 1414247, 1551866, 1486093, 1591806, 1309963, 1401891,
            1516198, 656234, 1010547, 962159, 1006520, 1026695, 1024279, 1159589, 1160137, 916840, 1201236, 1553592, 1530902,
            1212058, 1212927, 1207831, 1214440, 1217618, 1219196, 972426, 1219996, 905991, 1227563, 1218150, 1242914, 1243423,
            1236538, 1244942, 1247949, 1218201, 1060315, 1250418, 1238014, 1256157, 1228054, 1145090, 1265413, 1226007, 1268567,
            1095783, 1270893, 1271318, 1265028, 1204740, 1277404, 1209415, 1218166, 1147602, 1224446, 1285266, 1251045, 1259963,
            1294780, 1616665, 1199082, 1295507, 1104609, 1296903, 1306643, 1306846, 1305106, 1301492, 1302790, 1231413, 1312802,
            1269760, 1291229, 1314068, 1321428, 1321948, 1312382, 1324962, 1247497, 1263564, 1456014, 1331829, 1332491, 1230359,
            1321864, 1333766, 1326191, 1304849, 1332486, 1219160, 1341621, 1237246, 1292880, 1315176, 1348228, 1349148, 936594,
            1350172, 1352334, 1280749, 1354542, 1355784, 1284728, 1358979, 1360039, 1360504, 1328527, 1361649, 1059354, 1366026,
            1366249, 1366528, 1274547, 1204271, 1370110, 1320326, 1122021, 1331815, 1106024, 1286334, 1264361, 1376123, 1359732,
            1376782, 1326060, 1361623, 1255779, 1293068, 1379583, 1359965, 1337322, 1385834, 1329534, 1386574, 1388256, 1370447,
            1389150, 1377578, 1355330, 1382043, 1381565, 1374657, 1376357, 1396216, 1309664, 1396440, 1397359, 1397439, 1344951,
            1392844, 1380670, 1403077, 1258176, 802039, 1395377, 1066046, 1378994, 1385150, 1408911, 1409117, 1387584, 1331237,
            1412673, 1405045, 1398246, 1410917, 1319224, 1406556, 1386693, 1395684, 1418131, 1419368, 1384090, 1409923, 1401938,
            1423563, 1296239, 1426096, 1392778, 1426743, 1409615, 1405372, 1165898, 1287468, 1523214, 1417704, 1393732, 1429401,
            1406771, 1434360, 1288156, 1341615, 1436677, 1436790, 1436903, 1131023, 1399990, 1418612, 1209471, 1438486, 1318163,
            1406802, 1465856, 1422711, 1380858, 1439491, 1405942, 1333496, 1289546, 1416436, 1432668, 1421000, 1442267, 1414116,
            1418817, 1291543, 1445362, 1446009, 63319, 116721, 112565, 135254, 139156, 84192, 143377, 174032, 178753, 179227,
            155814, 182832, 189728, 181313, 221240, 221983, 121616, 226277, 232938, 190153, 183293, 223944, 249836, 242118, 228293,
            262766, 227404, 267757, 269104, 204021, 237133, 258724, 213621, 243001, 290482, 282200, 310766, 275201, 115617, 325771,
            213824, 336686, 315531, 321709, 655388, 324646, 691296, 301415, 180931, 116441, 347763, 707753, 673294, 714363, 347228,
            722147, 723945, 317943, 700014, 696210, 357452, 741468, 242607, 674205, 743902, 751241, 794824, 763643, 790401, 785525,
            675684, 705331, 799387, 723651, 836901, 834528, 853691, 853964, 835469, 730069, 742903, 870067, 781896, 859292, 882213,
            860282, 885228, 851943, 898729, 898731, 857684, 895900, 760034, 916925, 883813, 705735, 226531, 914941, 127830, 895714,
            929322, 926613, 911974, 813709, 974157, 961149, 983563, 1014060, 1035789, 972563, 1007650, 1008323, 778560, 949798,
            769359, 949000, 916869, 1095387, 1012597, 853065, 1105601, 1129123, 1059676, 1133887, 1164889, 1179719, 911664, 943599,
            1198167, 1200074, 1204141, 1209023, 1210678, 934612, 1214420, 1215777, 1220940, 1222323, 1224474, 1225092, 1226199,
            1227716, 1227864, 1215626, 1234777, 1242029, 1188503, 1249412, 1242355, 1254449, 759030, 1178165, 985102, 992645,
            1174523, 1266989, 1047251, 1274651, 996008, 1206265, 1213347, 1292340, 1275646, 1269402, 1283635, 1268772, 1258839,
            1201237, 1259134, 1028470, 1209242, 1210365, 1319833, 1363084, 1330354, 1412953, 1373089, 1374909, 1436006, 1273664,
            1466041, 1491060, 1501089, 1515250, 1518145];

        $updatedBankrupts = 0;
        $finished = 0;

        foreach ($clientIds as $clientId) {
            $client = $clientRepository->findByPk($clientId);
            $needToSleep = false;

            if (null === $client) {
                echo "Клиент не найден: $clientId";
                continue;
            }

            if (null === $client->getInn()) {
                $needToSleep = true;
            }

            $isBankrupt = $bankruptComponent->isClientBankrupt($client);

            if ($isBankrupt) {
                $updatedBankrupts++;
                echo "Успешно: $clientId";
            } else {
                if (null !== $client->getInn()) {
                    echo "Нет данных в ЕФРСБ о клиенте $clientId";
                } else {
                    echo "ИНН не найден: $clientId";
                }
            }

            echo PHP_EOL;

            $finished++;

            if ($needToSleep) {
                sleep(15);
            }
        }

        echo "Всего обработано $finished" . PHP_EOL;
        echo "Успешно обновлено $updatedBankrupts" . PHP_EOL;
    }

    /**
     * Переименование в постбэках lead_provider поля с bankirucheck на bankiru_check
     *
     * @param ILeadPostbackRepository $leadPostbackRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionAdequateNameBankiruCheck(
        ILeadPostbackRepository $leadPostbackRepository,
        TransactionInterface    $transaction
    ) {
        $leadPostbacks = $leadPostbackRepository->findAllByLeadProviderName('bankirucheck');
        /** @var LeadPostback $leadPostback */
        foreach ($leadPostbacks as $leadPostback) {
            $leadPostback->setLeadProvider('bankiru_check');
            $transaction->persist($leadPostback);
        }
        $transaction->run();
    }


    /**
     * @param GodzillaFactory $godzillaFactory
     * @return void
     */
    public function actionRecalcLoans(GodzillaFactory $godzillaFactory)
    {
        $godzila = $godzillaFactory::make();


        $loanIds = [
            26067,
            55619,
        ];


        foreach ($loanIds as $loanId) {
            $godzila->recalc($loanId, [], 1);
        }
    }

    /**
     * Создаёт файл csv с данными для скоринга по файлу xlsx со столбцами clientId и dateOfReport
     *
     * @param ClientRepository $clientRepository
     * @param LoanRepositoryInterface $loanRepository
     * @param KernelInfo $kernelInfo
     * @return void
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function actionCreateBatchRequestBankruptPredictionFile(
        ClientRepository        $clientRepository,
        LoanRepositoryInterface $loanRepository,
        KernelInfo              $kernelInfo,
    ) {
        $date = (new DateTimeImmutable())->format("Ymd");
        $filename = "3OM_SC_1_{$date}_0002.csv";
        $dir = $kernelInfo->getProjectPath() . '/common/docs_templates/temp';

        $scoringFile = fopen("$dir/$filename", 'w');

        $bankruptsList = IOFactory::load("$dir/BankruptsList_Loan_info.xlsx");

        $bankruptsList->setActiveSheetIndex(0);
        $sheet = $bankruptsList->getActiveSheet();
        $num = 0;
        $succeed = 0;

        fputcsv($scoringFile, [
            'num',
            'lastname',
            'firstname',
            'middlename',
            'birthday',
            'birthplace',
            'doctype',
            'docno',
            'docdate',
            'docplace',
            'pfno',
            'addr_reg_index',
            'addr_reg_total',
            'addr_fact_index',
            'addr_fact_total',
            'gender',
            'cred_type',
            'cred_currency',
            'cred_sum',
            'dateofreport',
            'reason',
            'reason_text',
            'consent',
            'consentdate',
            'consentenddate',
            'admcode_inform',
            'consent_owner',
            'private_inn',
            'phone_mobile',
            'phone_home',
            'phone_work',
        ], separator: ';');

        foreach ($sheet->getRowIterator(2) as $row) {
            $num++;
            $cellIterator = $row->getCellIterator();
            $clientId = intval($cellIterator->current()->getValue());
            $cellIterator->next();
            $dateOfReport = DateTimeImmutable::createFromFormat('m/d/Y', $cellIterator->current()->getFormattedValue());

            $client = $clientRepository->findByPk($clientId);
            $loan = $loanRepository->findLastActiveByClientIdAndDate($clientId, $dateOfReport);
            $app = $loan?->getApp();
            if (null === $app) {
                echo "{$num}) Для килента $clientId не найдено актуальной заявки на дату {$dateOfReport->format(DateFmt::D_DB)}\n";
                continue;
            }

            $addrRegIndex = $client->getPassportIAddress()->getPostIndex();
            $addrFactIndex = $client->getHomeIAddress()->getPostIndex();
            if (empty($addrRegIndex)) {
                $addrRegIndex = '000000';
            }

            if (empty($addrFactIndex)) {
                $addrFactIndex = '000000';
            }

            $confirmDate = $app->getApprovementDate() ?? $app->getDeclineDate();
            if (null !== $confirmDate) {
                $confirmDate = new DateTimeImmutable($confirmDate->format(DateFmt::DT_DB));
            }

            $smsConfirm = SmsConfirm::getLastSmsConfirmByClient($client, SmsConfirm::TYPE_BASE, $confirmDate);
            if (null === $smsConfirm || null === $smsConfirm->getConfirmDate()) {
                $smsConfirm = $app->getSmsConfirm();
            }

            $consent = 1;
            $consentDate = null;
            $consentEndDate = null;
            if (null !== $smsConfirm) {
                $consentDate = $smsConfirm->getConfirmDate();
            } elseif (null !== $app->bkiRating) {
                $consentDate = $app->bkiRating->getCreatedAt();
            }

            if (null === $consentDate) {
                echo "{$num}) Для клиента $clientId не найдена дата соглашения\n";
                continue;
            }

            $consentDate = new DateTimeImmutable($consentDate->format(DateFmt::DT_DB));
            if (!$loan->getStatus()->isActive() && $consentDate->modify('+6month') < $dateOfReport) {
                echo "{$num}) Для клиента $clientId истекло время соглашения\n";
                continue;
            }

            $consentDate = match ($dateOfReport < $consentDate) {
                true => $dateOfReport,
                false => $consentDate,
            };
            $consentEndDate = match ($loan->getStatus()->isActive()) {
                true => $dateOfReport->modify('+6month'),
                false => $consentDate->modify('+6month'),
            };

            $firstName = $client->getFirstName();
            $lastName = $client->getLastName();
            $middleName = $client->getMiddleName();
            $birthDate = $client->getBirthDate();
            $birthPlace = $client->getBirthPlace();
            $docNo = $client->getPassportSeries() . $client->getPassportNumber();
            $docDate = $client->getPassportDate();
            $docPlace = $client->getIssuingAuthority();
            if (empty($firstName) || empty($lastName) || null === $birthDate ||
                empty($birthPlace) || empty($docNo) || null === $docDate || empty($docPlace)) {
                continue;
            }

            fwrite($scoringFile, mb_convert_encoding(implode(';', [
                    $clientId, // num
                    $lastName, // lastname
                    $firstName, // firstname
                    $middleName, //middlename
                    $birthDate->format(DateFmt::D_APP_NEW), // birthday
                    $birthPlace, // birthplace
                    1, // doctype
                    $docNo, // docno
                    $docDate->format(DateFmt::D_APP_NEW), //docdate
                    $docPlace, // docplace
                    $client->getSnils()?->getValue(), // pfno
                    $addrRegIndex, // addr_reg_index
                    $client->getPassportIAddress()->getFull(), // addr_reg_total
                    $addrFactIndex, // addr_fact_index
                    $client->getHomeIAddress()->getFull(), // addr_fact_total
                    $client->getSex() + 1, // gender
                    19, // cred_type
                    'RUR', // cred_currency
                    $app->getRequestedSum(), // cred_sum
                    $app->getCreationDate()->format(DateFmt::D_APP_NEW), // dateofreport
                    1, // reason
                    '', // reason_text
                    $consent, // consent
                    $consentDate->format(DateFmt::D_APP_NEW), // consentdate
                    $consentEndDate->format(DateFmt::D_APP_NEW), // consentenddate
                    1, // admcode_inform
                    '', // consent_owner
                    $client->getInn()->getCode(), // private_inn
                    $client->getLoginPhone()->getDigits(), // phone_mobile
                    '', // phone_home
                    '', // phone_work
                ]) . "\n", 'WINDOWS-1251'));
            $succeed++;
            echo "{$num}) Клиент $clientId успешно добавлен в файл для скоринга \n";
        }

        fclose($scoringFile);
        echo "-------------------------------------------------------------- \n";
        echo ">>> Всего $succeed из $num добавлены в файл <<< \n\n";
    }

    /**
     * Выгружает сведения о размере задолженности по договорам по которым оформлены кредитные каникулы за промежуток времени в xls
     *
     * ./yiic Special exportCreditHolidaysReport --dateBegin='2022-03-01' --dateEnd='2022-06-30'
     *
     * @param string $dateBegin
     * @param string $dateEnd
     * @param PDO $pdo
     * @param LoggerInterface $logger
     * @param ChargeRepositoryInterface $chargeRepository
     * @return void
     * @throws BaseException
     * @throws CantSaveFileException
     */
    public function actionExportCreditHolidaysReport(
        string                    $dateBegin,
        string                    $dateEnd,
        PDO                       $pdo,
        LoggerInterface           $logger,
        ChargeRepositoryInterface $chargeRepository,
    ): void {
        $dateTimeBegin = DateFmt::dateFromDB($dateBegin);
        $dateTimeEnd = DateFmt::dateFromDB($dateEnd);

        $sql = <<<SQL
        SELECT `ch`.`loan_id`, `ch`.`date_start`, `lh`.`sum` FROM `credit_holidays` AS `ch` INNER JOIN `loan_history` AS `lh` ON 
            `ch`.`loan_id` = `lh`.`loan_id` WHERE DATE(`ch`.`date_start`) BETWEEN :start AND :end AND `ch`.`date_start` = `lh`.`active_begin`;
        SQL;

        $params = [
            ':start' => $dateBegin,
            ':end' => $dateEnd,
        ];

        $query = $pdo->prepare($sql);
        $query->execute($params);
        $loans = $query->fetchAll(PDO::FETCH_CLASS);

        foreach ($loans as $loan) {
            $date = DateFmt::fromDB($loan->date_start);
            $percent = $chargeRepository->getSumPercentTotalUpTo($loan->loan_id, $date);

            $counter = 1;
            $interval = new DateIntervalForm();
            $interval->setBegin($dateTimeBegin);
            $interval->setEnd($dateTimeEnd);

            $data [] = [
                $loan->loan_id,
                $loan->sum,
                $percent,
                $loan->sum + $percent,
            ];
            (new XlsCreditHolidaysReportExport($data, $interval, $counter, $logger))->save();
        }
    }

    /**
     * Заполняет поле reg_mode в таблице Account
     *
     * @param IClientRepository $clientRepository
     * @param CreditApplicationRepositoryInterface $creditApplicationRepository
     * @param TransactionInterface $transaction
     * @param AccountRepositoryInterface $accountRepository
     * @param EsiaRequestPersonDataRepositoryInterface $esiaRequestPersonDataRepository
     * @param PDO $pdo
     * @return void
     */
    public function actionFillAccountTableRegMode(
        IClientRepository                        $clientRepository,
        CreditApplicationRepositoryInterface     $creditApplicationRepository,
        TransactionInterface                     $transaction,
        AccountRepositoryInterface               $accountRepository,
        EsiaRequestPersonDataRepositoryInterface $esiaRequestPersonDataRepository,
        PDO                                      $pdo,
        LoggerInterface                          $logger,
    ): void {


        // Цикл для чекера bankiru
        if (null !== $creditApplicationRepository->findChunkWithRegistrationModeChecker()) {
            $limit = 500;
            $i = 0;
            do {
                foreach ($creditApplicationRepository->findChunkWithRegistrationModeChecker($i * $limit, $limit) as $creditApplication) {
                    $account = $accountRepository->findByClientId($creditApplication->getClientId());
                    $account->setRegMode(RegistrationMode::makeChecker());
                    $transaction->persist($account)->run();
                }
                $i++;
            } while (!empty($creditApplicationRepository->findChunkWithRegistrationModeChecker($i * $limit, $limit)));
        }
        echo 'Аккаунты зарегистрированные через чекер bankiru заполнены';
        echo PHP_EOL;

        // Первый цикл для Esia, перенос из Client
        if (null !== $clientRepository->findChunkRegistrationModeEsia()) {
            $limit = 500;
            $i = 0;
            do {
                foreach ($clientRepository->findChunkRegistrationModeEsia($i * $limit, $limit) as $client) {
                    try {
                        $account = $accountRepository->findByClientId($client->getId());
                        $account->setRegMode(RegistrationMode::makeEsia());
                        $transaction->persist($account)->run();
                    } catch (Exception $exception) {
                        $logger->error('Ошибка с аккаунтом: ID - ' . $client->getId() . 'ошибка: ' . $exception->getMessage());
                        echo PHP_EOL;
                        continue;
                    }
                }
                $i++;
                echo $i * 500 . ' есия акаунтов из первого цикла заполнены.';
                echo PHP_EOL;
            } while (!empty($clientRepository->findChunkRegistrationModeEsia($i * $limit, $limit)));
        }
        echo 'Первая часть аккаунтов зарегистрированных через Esia заполнена';
        echo PHP_EOL;


        // Второй цикл для Esia. Из esia_request_person_data берет дату и ФИ из тела ответа,
        // и если в этот день был зарегестрирован клиент с такими фио то делает запись в reg_mode Account таблицу
        if (null !== $esiaRequestPersonDataRepository->findChunkOfAll()) {
            $limit = 500;
            $i = 0;
            do {
                foreach ($esiaRequestPersonDataRepository->findChunkOfAll($i * $limit, $limit) as $personData) {
                    try {
                        if ($personData->getResourceType()->isPersonalInfo()) {
                            $client = $clientRepository->findClientWithRegistrationDateAndFI(
                                json_decode($personData->getResponseData(), true)["lastName"],
                                json_decode($personData->getResponseData(), true)["firstName"],
                                $personData->getCreatedAt()
                            );
                        }

                        if ($personData->getResourceType()->isDocInfo()) {
                            $client = $clientRepository->findClientWithRegistrationDateAndPassport(
                                json_decode($personData->getResponseData(), true)["series"],
                                json_decode($personData->getResponseData(), true)["number"],
                                $personData->getCreatedAt()
                            );
                        }

                        if (null !== $client) {
                            $account = $accountRepository->findByClientId($client->getId());
                            $account->setRegMode(RegistrationMode::makeEsia());
                            $transaction->persist($account)->run();
                        }
                    } catch (Exception $exception) {
                        $logger->error('Ошибка с аккаунтом: ID - ' . $client->getId() . 'ошибка: ' . $exception->getMessage());
                        echo PHP_EOL;
                        continue;
                    }
                }
                $i++;
                echo $i * 500 . ' есия акаунтов из второго цикла заполнены.';
                echo PHP_EOL;
            } while (!empty($esiaRequestPersonDataRepository->findChunkOfAll($i * $limit, $limit)));
        }
        echo 'Вторая часть аккаунтов зарегистрированных через Esia заполнена';
        echo PHP_EOL;


        // Заполняет оставшиеся аккаунты обычным способом регистрации
        $sql = 'UPDATE account
        SET reg_mode = :usualMode
        WHERE `reg_mode` IS NULL';

        $params = [
            ':usualMode' => RegistrationMode::USUAL,
        ];

        $query = $pdo->prepare($sql);
        $query->execute($params);

        echo 'Аккаунты зарегистрированные обычным способом заполнены';
        echo PHP_EOL;
        echo "\nКоманда FillAccountTableRegMode завершена\n\n";
    }

    /**
     * Очищает кэш по застрявшим платёжкам
     *
     * @param GFKCacheInterface $gfkCache
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function actionRemoveStruggledTask(GFKCacheInterface $gfkCache)
    {
        $gfkCache->delete("paymentOrders:docId:66629");
    }

    /**
     * Отправка Скиба кредитных историй по банкротам
     * в рамках задачи GFK-4218
     *
     * @link https://track.coderocket.ru/issue/GFK-4218
     *
     * @param ClientRepositoryInterface $clientRepository
     * @param LoggerInterface $logger
     * @param EquifaxService $equifaxService
     * @param int $lengthTest
     *
     * @param int $offsetTest
     *
     * @return void
     * @throws Exception
     */
    public function actionSendCreditHistoryForSkibaByBankrupt(
        ClientRepositoryInterface $clientRepository,
        LoggerInterface           $logger,
        EquifaxService            $equifaxService,
        int                       $lengthTest = 0,
        int                       $offsetTest = 0,
    ) {
        $file = __DIR__ . '/../../../common/temp/clientsIdBankrupt.txt';
        $resultList = ['success' => [], 'error' => []];
        $i = 0;

        $addCount = function (string $type, int $clientId) use (&$resultList, &$i) {
            $resultList[$type][] = $clientId;
            $i++;
        };

        $clientsIds = file(filename: $file);

        /** Для тестового запроса берём только 1 элемент */
        if (0 !== $lengthTest) {
            $clientsIds = array_slice(array: $clientsIds, offset: $offsetTest, length: $lengthTest);
        }

        foreach ($clientsIds as $clientId) {
            $client = $clientRepository->findByPk(clientId: $clientId);

            if (null === $client) {
                echo "$i | ❌ | Клиент $clientId не найден" . PHP_EOL;
                $addCount(type: 'error', clientId: $clientId);
                continue;
            }

            $loan = $client->getCurrentLoan();

            if (null === $loan) {
                echo "$i | ❌ | Клиент $clientId - нет текущего займа" . PHP_EOL;
                $addCount(type: 'error', clientId: $clientId);
                continue;
            }

            if (!$loan->getStatus()->isActive()) {
                echo "$i | ❌ | Клиент $clientId - займ {$loan->getId()}
                     имеет статус {$loan->getStatus()->getTitle()}. Ожидается \"Активный\"";
                $addCount(type: 'error', clientId: $clientId);
                continue;
            }


            /** 1 запрос в минуту, иначе не справляется */
            sleep(seconds: 60);

            if (null !== $isSend) {
                echo "$i | ❌ | Клиент $clientId - не отправился запрос на получение КИ";
                $addCount(type: 'error', clientId: $clientId);
                continue;
            }

            echo "$i | ✅️ | Успешно - Клиент #$clientId" . PHP_EOL;
            $addCount(type: 'success', clientId: $clientId) . PHP_EOL;
        }

        $countSuccess = count($resultList['success']);
        $countError = count($resultList['error']);

        echo PHP_EOL . PHP_EOL . "Сделано всего: $i шт." . PHP_EOL;
        echo "Успешных: $countSuccess шт." . PHP_EOL;
        echo "Ошибочных: $countError шт." . PHP_EOL;

        echo PHP_EOL . PHP_EOL;

        echo 'ID ошибочных клиентов: ' . PHP_EOL;

        foreach ($resultList['error'] as $clientId) {
            echo $clientId . PHP_EOL;
        }
    }

    /**
     * Проверяет клиентов с активными займами на банкротство
     *
     * @param LoanRepositoryInterface $loanRepository
     * @param BankruptComponent $bankruptComponent
     * @return void
     * @throws Exception
     */
    public function actionCheckClientWithActiveLoansOnBankruptcy(
        LoanRepositoryInterface $loanRepository,
        BankruptComponent       $bankruptComponent
    ) {
        $countActiveLoans = $loanRepository->countAllActive();
        $number = 1;
        /** @var LoanBase $loan */
        foreach ($loanRepository->getActiveLoansGenerator() as $loan) {
            $client = $loan->getClient();
            $isBankrupt = $bankruptComponent->isClientBankrupt($client);
            $client->refresh();
            if ($isBankrupt) {
                $client->setBankrupt($isBankrupt);
                echo "Обработано $number из $countActiveLoans: клиент №{$client->getId()} - банкрот \n";
            } elseif (null === $isBankrupt) {
                echo "Обработано $number из $countActiveLoans: клиент №{$client->getId()} - ИНН не найден \n";
            } else {
                echo "Обработано $number из $countActiveLoans: клиент №{$client->getId()} - НЕ банкрот \n";
            }
        }

        echo "Проверка завершена \n";
    }

    /**
     * Проверяет валидность ИНН клиентов
     *
     * @param ClientRepositoryInterface $clientRepository
     * @return void
     */
    public function actionCheckClientInnValidity(
        ClientRepositoryInterface $clientRepository
    ) {
        $allClientsWithInn = $clientRepository->countAllWithInn();
        $invalidInn = 0;
        /** @var ClientBase $client */
        foreach ($clientRepository->getClientsWithInnGenerator() as $client) {
            try {
                $message = $client->getInn()?->getCode();
            } catch (Throwable $e) {
                $invalidInn++;
                $message = $e->getMessage();
            }

            echo "Клиент №{$client->getId()}: $message \n";
        }

        echo "Всего невалидных ИНН $invalidInn из $allClientsWithInn \n";
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function actionCheckResend(LoggerInterface $logger)
    {
        /** @var Check[] $checks */
        $checks = Check::model()->findAllByAttributes(['status' => 'error', 'response' => '']);

        foreach ($checks as $check) {
            if ($check->getCreatedAt() < new DateTimeImmutable('2022-08-10 00:00:00')) {
                continue;
            }

            $description = null;
            $destination = $check->incomingTransfer->getDestination();
            if ($destination->isCommissionInsurance()) {
                $description = 'Услуга по включению в список застрахованных лиц по договору добровольного коллективного страхования';
            }

            if ($destination->isCommissionInsuranceCard()) {
                $description = 'Услуга по включению в список застрахованных лиц по договору добровольного коллективного страхования банковских карт';
            }

            if (null === $description){
                echo 'Нет назначения у чека ' . $check->id . PHP_EOL;
                continue;
            }

            $config = new InsuranceCheckConfig(
                $logger,
                $check->incomingTransfer->getMoney(),
                $check->incomingTransfer->loan->client->getEmail(),
                $description,
                $check->getCompany()
            );
            $sender = new CheckSender($config);
            $jsonResp = $sender->send();

            if (false === $jsonResp) {
                $jsonResp = '';
            }

            $resp = json_decode($jsonResp, true);
            $status = Check::STATUS_ERROR;
            if (null !== $resp && isset($resp['Response']) && 0 === (int)$resp['Response']['Error']) {
                $status = Check::STATUS_SUCCESS;
            } elseif (!isset($resp['Response'])) {
                echo 'Ошибка при обработке ответа чека: ' . print_r($resp, true) . PHP_EOL;
            }

            $check->setStatus($status);
            $check->setResponse($jsonResp);
            $check->save(false, ['status', 'response']);
            echo 'Отправлен чек №' . $check->getId() . ' на адрес ' . $check->incomingTransfer->loan->client->getEmail() . PHP_EOL;
        }
    }

    /**
     * Заполняет телефоны сотрудникам, у которых его либо по какой-то причине не оказалось,
     * либо, по удивительному стечению обстоятельств, он оказался невалидным
     *
     * @param IStaffRepository $staffRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFillStaffPhones(IStaffRepository $staffRepository, TransactionInterface $transaction): void
    {
        /** @var \Glavfinans\Core\Entity\Staff\Staff[] $staffs */
        $staffs = $staffRepository->findAll();
        foreach ($staffs as $staff) {
            try {
                $staff->getPhone();
            } catch (\Glavfinans\Core\Exception\BoundsException|EmptyValueException $e) {
                $staff->setPhone(new \Glavfinans\Core\Phone\Phone('89990000000'));
                $transaction->persist($staff);
                $transaction->run();
                echo "Пустой телефон у сотрудника {$staff->getId()}" . PHP_EOL;
                continue;
            }
            echo "Прошел сотрудник {$staff->getId()}" . PHP_EOL;
        }
    }

    /**
     * Заполняет email сотрудникам, у которых его либо по какой-то причине не оказалось
     *
     * @param IStaffRepository $staffRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFillEmail(IStaffRepository $staffRepository, TransactionInterface $transaction): void
    {
        /** @var \Glavfinans\Core\Entity\Staff\Staff[] $staffs */
        $staffs = $staffRepository->findAll();
        foreach ($staffs as $staff) {
            if (empty($staff->getEMail())) {
                $staff->setEMail('mail@email.ru');
                $transaction->persist($staff);
                $transaction->run();
                echo "Пустой email у сотрудника {$staff->getId()}" . PHP_EOL;
                continue;
            }
            echo "Прошел сотрудник {$staff->getId()}" . PHP_EOL;
        }
    }

    /**
     * Команда для заполнения system_id у пустых payment and incoming_transfer
     *
     * @param PaymentRepository $paymentRepository
     * @param IncomingTransferRepositoryInterface $incomingTransferRepository
     * @return void
     */
    public function actionUpdateSystemId(
        PaymentRepository                   $paymentRepository,
        IncomingTransferRepositoryInterface $incomingTransferRepository,
    ) {
        $i = 0;
        echo "\n - - - - system_id начал обновляться - - - - \n\n";
        foreach ($paymentRepository->findAllChunkWithEmptySystemId() as $payments) {
            /** @var Payment $payment */
            foreach ($payments as $payment) {
                $uuid = "was_empty_" . Uuid::uuid4()->toString();
                $payment->setSystemId(systemId: $uuid);
                $payment->save(false);
                $i++;
                echo "$i) Обновлен systemId у payment с id = {$payment->getId()}\n";

                $incomings = $incomingTransferRepository->findAllByPaymentId(paymentId: $payment->getId());
                $j = 0;
                /** @var IncomingTransfer $incoming */
                foreach ($incomings as $incoming) {
                    $incoming->setSystemId(systemId: $uuid);
                    $incoming->save(false);
                    $j++;
                    echo "$i.$j) Обновлен systemId у incomingTransfer с id = {$incoming->getId()}\n";
                }
            }
        }

        echo "\n - - - - Команда закончила выполнение - - - - \n\n";
    }

    /**
     * Разовая команда для обновления данных по продаже займов, была перепродажа финзе, а в loan данные не обновлены
     * ./yiic special UpdateSaleCompanyByLoans
     *
     * @param LoanRepositoryInterface $loanRepository
     *
     * @return void
     */
    public function actionUpdateSaleCompanyByLoans(LoanRepositoryInterface $loanRepository)
    {
        $loanIds = [
            165, 170, 237, 497, 624, 629, 726, 880, 1015, 1138, 1394, 1727, 1843, 3770, 3983, 4327, 4403,
            4488, 4953, 4996, 5028, 5063, 5085, 5404, 5409, 5426, 5497, 5923, 6620, 6872, 7018, 7102, 7607, 8587
        ];

        $i = 0;
        foreach ($loanIds as $loanId) {
            $loan = $loanRepository->findByPk($loanId);

            if (null === $loan) {
                echo 'Не найден займ ' . $loanId . PHP_EOL;
                continue;
            }

            if ('Финзащита' === $loan->sale_company) {
                echo 'Займ ' . $loanId . ' уже обновлен' . PHP_EOL;
                continue;
            }

            try {
                $loan->setSaleDate(new DateTimeImmutable('2017-03-10 00:00:00'));
                $loan->setSaleCompany('Финзащита');
                $loan->save(false, ['sale_date', 'sale_company']);
            } catch (Throwable $exception) {
                echo 'Ошибка при обнолвении займа №' . $loan->getId() . '; ' . $exception->getMessage() . PHP_EOL;
                continue;
            }
            echo 'Успешно обновлены данные по займу №' . $loan->getId() . PHP_EOL;;
            $i++;
        }
        echo 'Займов обновлено: ' . $i . ' из ' . count($loanIds) . PHP_EOL;
    }

    /**
     * Разовая команда для списания активных займов Финзащиты. Списание за счет резервов
     * Сначала выполнить команду actionUpdateSaleCompanyByLoans
     * ./yiic special WriteOffFinzLoans
     *
     * @param LoanRepository $loanRepository
     * @param LoggerInterface $logger
     * @param PercentRateService $percentRateService
     *
     * @return void
     * @throws BaseException
     * @throws CException
     * @throws NotFoundException
     * @throws Throwable
     */
    public function actionWriteOffFinzLoans(
        LoanRepositoryInterface $loanRepository,
        LoggerInterface         $logger,
        PercentRateService      $percentRateService,
    ): void {
        Yii::import('application.modules.equifax.models.*');

        $i = 0;
        $loanIds = [
            165, 170, 237, 497, 624, 629, 726, 880, 1015, 1138, 1193, 1252, 1394, 1578, 1685, 1727, 1803, 1843,
            2186, 2231, 2314, 2332, 2342, 2454, 2607, 2739, 3219, 3488, 3730, 3770, 3983, 4014, 4327, 4403, 4488, 4522,
            4947, 4948, 4953, 4996, 4998, 5028, 5063, 5085, 5126, 5233, 5282, 5316, 5404, 5409, 5426, 5497, 5594, 5923,
            5976, 6280, 6620, 6626, 6631, 6658, 6682, 6872, 7018, 7102, 7360, 7475, 7607, 7693, 7695, 8047, 8121, 8494,
            8587, 8762, 8787, 9282, 9470, 9663, 9753, 9971, 10035, 10059, 10210, 10229, 10296, 10384, 10410, 10417,
            10508, 10526, 10681, 10751, 11018, 11130, 11601, 11816, 11843, 11998, 12143, 12266, 12329, 12500, 12573,
            12739, 12967, 13171, 13178, 13594, 13641, 13825, 14039, 14457, 14474, 14505, 14582, 15158, 15244, 15394,
            15527, 15629, 15690, 15936
        ];

        foreach ($loanIds as $loanId) {

            $loan = $loanRepository->findByPk($loanId);
            if (null === $loan) {
                echo 'Не найден займ ' . $loanId . PHP_EOL;
                continue;
            }

            if ($loan->getStatus()->isPayOffBadDebt()) {
                echo 'Займ №' . $loanId . ' уже в статусе "Списан по безнадежному долгу"' . PHP_EOL;
                continue;
            }

            if (!$loan->lastSale->isBuyerFinza()) {
                echo 'Займ №' . $loanId . ' не продан Финзащите ' . PHP_EOL;
                continue;
            }

            $app = $loan->getApp();
            if (null === $app) {
                echo 'Не найдена заявка по займу №' . $loanId . PHP_EOL;
                continue;
            }
            $transaction = InnerTransaction::begin();

            $walletPay = new WalletPaymentForm();
            $walletPay->system = IncomingTransfer::TYPE_WRITE_OFF_RESERVES;
            $walletPay->system_id = uniqid();
            $walletPay->date = DateFmt::nowDB(true);
            $walletPay->loan_id = $loan->getId();
            $walletPay->client_id = $loan->getClientId();
            $walletPay->application_id = $loan->getApp()->getId();
            $walletPay->company_id = Company::FINZ_ID;
            $walletPay->recurrent = 0;
            $walletPay->sum = $loan->getRemainingSum();
            $walletPay->rrn = '';
            $walletPay->gw_id = '';
            $walletPay->comment = '';

            try {
                $loan->pay($walletPay, $logger);
                $loan->refresh();
            } catch (Exception $exception) {
                echo 'Ошибка при оплате займа №' . $loan->getId() . '; ' . $exception->getMessage() . PHP_EOL;
                $transaction->rollback();
                continue;
            }

            try {
                $loan->setStatus(Status::makeBadDebt());
                $loan->setCloseDate(new DateTimeImmutable());
                $loan->save(false, ['loan_status_id', 'close_date']);
            } catch (Exception $exception) {
                echo 'Ошибка при сохранении займа №' . $loan->getId() . '; ' . $exception->getMessage() . PHP_EOL;
                $transaction->rollback();
                continue;
            }

            if (!$app->setEquParams(new DateTimeImmutable(), CreditApplicationBase::EQUIFAX_OFF)) {
                echo 'Не удалось сохранить дату обработки и статус Equifax заявке ' . $app->getId() . PHP_EOL;
                $transaction->rollback();
                continue;
            }

            try {
                /** Отправляем отчет в Equifax */
                LoanEquifaxHelper::sendBadDebtReport(
                    loan: $loan,
                    date: new DateTimeImmutable(),
                    logger: $logger,
                    percentRateService: $percentRateService,
                );
            } catch (Exception $exception) {
                echo 'Ошибка при отправке в эквифакс по займу №' . $loan->getId() . '; ' . $exception->getMessage() . PHP_EOL;
                $transaction->rollback();
                continue;
            }

            try {
                $transaction->commit();
            } catch (Throwable $exception) {
                echo 'АЛАРМ!!!! Ошибка при коммите, займ №' . $loan->getId() . '; ' . $exception->getMessage() . PHP_EOL;
                continue;
            }

            echo 'Успешно списан займ №' . $loan->getId() . PHP_EOL;;
            $i++;
        }
        echo 'Займов успешно списано по финзащите: ' . $i . ' из ' . count($loanIds) . PHP_EOL;
    }

    /**
     * Меняет номера, клиентам, у которых они невалидные
     *
     * @param ClientRepositoryInterface $clientRepository
     * @param CommentService $commentService
     * @param CommentFactory $commentFactory
     * @return void
     * @throws Exception
     */
    public function actionFixClientLogins(
        ClientRepositoryInterface $clientRepository,
        CommentService            $commentService,
        CommentFactory            $commentFactory
    ): void {
        $limit = 500;
        $count = 0;
        $lastDigit = 10;
        $countClients = 0;

        do {
            /** @var ClientBase[] $clients */
            $clients = $clientRepository->findAllByLimitForCheckPhones($limit);
            foreach ($clients as $client) {
                $countClients++;
                try {
                    $client->getLoginPhone();
                    $client->setLogin($client->getLoginPhone()->getDigits());
                    $client->save(false, ['login']);
                } catch (Throwable $exception) {
                    $client->refresh();
                    $oldLogin = $client->login;
                    $login = $client->login;
                    $login[0] = 7;
                    $client->login = $login;
                    try {
                        $client->save(false, ['login']);
                    } catch (Throwable $exception) {
                        $client->refresh();
                        $client->setLogin('799999999' . $lastDigit);
                        $lastDigit++;
                        $comment = $commentFactory->makeWarning(
                            $client->getId(),
                            "У клиента был невалидный номер: $oldLogin Сменили на $client->login"
                        );
                        $commentService->add($comment);
                        $client->save(false, ['login']);
                    }
                    $count++;
                }
            }

            echo "Отработано $countClients" . PHP_EOL;
        } while (!empty($clients));

        echo "Невалидных логинов $count" . PHP_EOL;
    }

    /**
     * Проверяет всех клиентов на валидность email
     *
     * @param ClientRepositoryInterface $clientRepository
     * @param CommentService $commentService
     * @param CommentFactory $commentFactory
     * @return void
     * @throws Exception
     */
    public function actionFixEmail(
        ClientRepositoryInterface $clientRepository,
        CommentService            $commentService,
        CommentFactory            $commentFactory
    ): void {
        $limit = 500;
        $count = 0;
        $countAll = 0;

        do {
            /** @var ClientBase[] $clients */
            $clients = $clientRepository->findAllByLimitAndOffsetForFixEmail($limit);
            foreach ($clients as $client) {
                $countAll++;
                if ('' === $client->getEmail() || !preg_match('/^[a-zA-Z0-9.!#$%&\'*+=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/', $client->getEmail())) {
                    $oldEmail = $client->getEmail();
                    $client->setEmail('mail@mail.ru');
                    $client->save(false, ['email']);
                    $comment = $commentFactory->makeNote($client->getId(), "Привязанная почта была невалидна. Старая почта $oldEmail. Новая почта mail@mail.ru");
                    $commentService->add($comment);
                    $count++;
                }
            }

            echo "Отработано $countAll" . PHP_EOL;
        } while (!empty($clients));

        echo "Поменяли email у $count клиентов" . PHP_EOL;
    }

    /**
     * Разовая команда, устанавливает snils = null клиентам у кого (15 шт.):
     *  - snils пустой
     *
     * @param CommentFactory $commentFactory
     * @param CommentService $commentService
     * @return void
     * @throws Exception
     */
    public function actionChangeEmptySnilsOnNull(
        CommentFactory $commentFactory,
        CommentService $commentService
    ): void {

        $criteria = new CDbCriteria();
        $criteria->addCondition('snils = :snils');
        $criteria->params[':snils'] = '';
        /** @var  $clients - клиенты у кого snils пустой */
        $clients = ClientBase::model()->findAll($criteria);

        $changedSnils = 0;

        foreach ($clients as $client) {

                $transaction = InnerTransaction::begin();
            try {
                $client->setSnils(null);

                if (!$client->save(false, ['snils'])) {
                    throw new DomainException('Ошибка при сохранении СНИЛС у клиента № ' . $client->getId());
                }

                /** Добавляем комментарий клиенту об изменении СНИЛС */
                $comment = $commentFactory->makeWarning(clientId: $client->getId(), text: "СНИЛС изменён с пустого на null");
                $commentService->add($comment);

                $transaction->commit();

                $changedSnils++;
                echo 'У клиента ' . $client->getId() . ' изменён snils с пустого на null' . PHP_EOL;

            } catch (Throwable $e) {

                echo 'Клиент № ' . $client->getId() . ' - ошибка ' . $e->getMessage() . PHP_EOL;
                $transaction->rollback();
                continue;
            }
        }

        echo "СНИЛС успешно изменён у $changedSnils из " . count($clients) . '.' . PHP_EOL;
    }

    /**
     * Разовая команда, устанавливает snils = null клиентам у кого (53 шт.):
     *  - в snils пять цифр повторяется (00000000000, 77777778888 и т.д.)
     *  - из этих выше не проходят по контрольной сумме
     *  - из этих выше нет активных займов
     *
     * @param CommentFactory $commentFactory
     * @param CommentService $commentService
     * @return void
     * @throws Exception
     */
    public function actionChangeSnilsWithDigitsRepeatingOnNull(
        CommentFactory $commentFactory,
        CommentService $commentService
    ): void {

        $criteria = new CDbCriteria();
        $criteria->addCondition('snils REGEXP :match');
        $criteria->params[':match'] = '0{5}|1{5}|2{5}|3{5}|4{5}|5{5}|6{5}|7{5}|8{5}|9{5}';
        /** @var  $clients - клиенты у кого в snils пять цифр повторяется */
        $clients = ClientBase::model()->findAll($criteria);

        $changedSnils = 0;

        foreach ($clients as $client) {

            if (!$client->hasCurrentLoan() && !$client->getSnils()->check()) {

                $transaction = InnerTransaction::begin();
                try {
                    $oldSnils = $client->getSnils()?->getValue();
                    $client->setSnils(null);

                    if (!$client->save(false, ['snils'])) {
                        throw new DomainException('Ошибка при сохранении СНИЛС у клиента № ' . $client->getId());
                    }

                    /** Добавляем комментарий клиенту об изменении СНИЛС */
                    $comment = $commentFactory->makeWarning(clientId: $client->getId(), text: "СНИЛС изменён с $oldSnils на null");
                    $commentService->add($comment);

                    $transaction->commit();

                    $changedSnils++;
                    echo 'У клиента № ' . $client->getId() . " изменён snils с $oldSnils на null" . PHP_EOL;
                } catch (Throwable $e) {
                    echo 'Клиент № ' . $client->getId() . ' - ошибка ' . $e->getMessage() . PHP_EOL;
                    $transaction->rollback();
                    continue;
                }

            } else {
                echo 'У клиента № ' . $client->getId() . ' snils не изменён, т.к. имеется активный займ или СНИЛС корректный по контрольной сумме' . PHP_EOL;
            }
        }

        echo "СНИЛС успешно изменён у $changedSnils из " . count($clients) . '.' . ' Должно быть 53 измененных.' . PHP_EOL;
    }

    /**
     * Разовая команда, устанавливает snils = null клиентам у кого (12 шт.):
     *  - в snils присутствует символ "-" (168-124-890 и т.д.)
     *  - из этих выше нет активных займов
     *
     * @param CommentFactory $commentFactory
     * @param CommentService $commentService
     * @return void
     * @throws Exception
     */
    public function actionChangeSnilsWithHyphensOnNull(
        CommentFactory $commentFactory,
        CommentService $commentService
    ): void {

        $criteria = new CDbCriteria();
        $criteria->addCondition('snils LIKE :snils');
        $criteria->params[':snils'] = '%-%';
        /** @var  $clients - клиенты у кого в snils имеется "-" */
        $clients = ClientBase::model()->findAll($criteria);

        $changedSnils = 0;

        foreach ($clients as $client) {


            if (!$client->hasCurrentLoan()) {
                $transaction = InnerTransaction::begin();
                try {
                    $oldSnils = $client->getSnils()?->getValue();
                    $client->setSnils(null);

                    if (!$client->save(false, ['snils'])) {
                        throw new DomainException('Ошибка при сохранении СНИЛС у клиента № ' . $client->getId());
                    }

                    /** Добавляем комментарий клиенту об изменении СНИЛС */
                    $comment = $commentFactory->makeWarning(clientId: $client->getId(), text: "СНИЛС изменён с $oldSnils на null");
                    $commentService->add($comment);

                    $transaction->commit();
                    $changedSnils++;
                    echo 'У клиента № ' . $client->getId() . " изменён snils с $oldSnils на null" . PHP_EOL;
                } catch (Throwable $e) {
                    echo 'Клиент № ' . $client->getId() . ' - ошибка ' . $e->getMessage() . PHP_EOL;
                    $transaction->rollback();
                    continue;
                }
            } else {
                echo 'У клиента № ' . $client->getId() . ' snils не изменён, т.к. имеется активный займ' . PHP_EOL;
            }
        }

        echo "СНИЛС успешно изменён у $changedSnils из " . count($clients) . '.' . PHP_EOL;
    }

    /**
     * Устанавливает тип alfa-bank платежам с ошибочным типом other c 08 сентября 2022 года
     * ./yiic special replaceOtherTypePayment
     *
     * @return void
     */
    public function actionReplaceOtherTypePayment(): void
    {
        /** Дата, с которой началось неправильная запись type в payment */
        $dateStartError = (new DateTimeImmutable(datetime: '08-09-2022'))->setTime(hour: 0, minute: 0);

        /** @var Payment[] $itsWithError Платежи с неверным type */
        $paymentsWithError = Payment::model()->findAll(
            condition: 'type = :typeOther AND payment_date >= :dateBegin',
            params: [
                ':typeOther' => PaymentType::makeOther()->getValue(),
                ':dateBegin' => $dateStartError->format(format: DateFmt::DT_DB),
            ]
        );

        echo 'Найдено ошибочных платежей ' . count($paymentsWithError) . ' с ' . $dateStartError->format(format: DateFmt::DT_DB) . PHP_EOL;

        $countSave = 0;
        foreach ($paymentsWithError as $payment) {
            $payment->setType(paymentType: PaymentType::makeAlfaBank());

            if (!$payment->save(runValidation: false, attributes: ['type'])) {
                echo 'Ошибка сохранения type у платежа #' . $payment->getId() . PHP_EOL;
            } else {
                $countSave++;
            }
        }

        echo 'Всего исправлено ' . $countSave . ' платежей' . PHP_EOL;
    }

    /**
     * Правит логины, которые состоят из одной цифры
     *
     * @param CommentService $commentService
     * @param CommentFactory $commentFactory
     * @return void
     * @throws Exception
     */
    public function actionFixLogin(CommentService $commentService, CommentFactory $commentFactory): void
    {
        $clients = ClientBase::model()->findAll('login = "7"');
        $lastDigit = 100;
        $count = 0;
        foreach ($clients as $client) {
            try {
                $client->getLoginPhone();
                $client->setLogin($client->getLoginPhone()->getDigits());
                $client->save(false, ['login']);
            } catch (Throwable $exception) {
                $oldLogin = $client->login;
                $client->refresh();
                $client->setLogin('79999999' . $lastDigit);
                $lastDigit++;
                $comment = $commentFactory->makeWarning(
                    $client->getId(),
                    "У клиента был невалидный номер: $oldLogin Сменили на $client->login"
                );
                $commentService->add($comment);
                $client->save(false, ['login']);
                $count++;
            }
        }
        echo "Отработано $count" . PHP_EOL;
    }

    /**
     * Заполняет num в займах, где это поле null
     *
     * @return void
     */
    public function actionFillNumInLoan(): void
    {
        /** @var LoanBase[] $loans */
        $loans = LoanBase::model()->findAll('num IS NULL');
        $pdo = \Glavfinans\Core\PDOGfk\PDOGfk::getInstance()->getPDO();
        foreach ($loans as $loan) {
            $sql = 'SELECT COUNT(`id`) FROM `loan` WHERE `issue_date` < :loanIssueDate AND `client_id` = :clientId';
            $params = [
                ':loanIssueDate' => $loan->getIssueDate()->format(DateFmt::DT_DB),
                ':clientId' => $loan->getClientId(),
            ];
            $query = $pdo->prepare($sql);
            $query->execute($params);
            $loan->setNum($query->fetchColumn() + 1);
            $loan->save(false, ['num']);
        }
    }

    /**
     * @param LoanSaleRepositoryInterface $loanSaleRepository
     * @param \Glavfinans\Repository\Loan\LoanRepositoryInterface $loanRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFillFinzIdInLoanCompanyId(
        LoanSaleRepositoryInterface                         $loanSaleRepository,
        \Glavfinans\Repository\Loan\LoanRepositoryInterface $loanRepository,
        TransactionInterface $transaction,
    ): void {
        $ids = $loanSaleRepository->findAllSoldLoanIdsByCompanyId(\Glavfinans\Domain\Entity\Company::FINZ_ID);
        $countActive = 0;
        $count = 0;

        foreach ($ids as $id) {
            $loanSale = $loanSaleRepository->findLastByLoanId($id['loanId']);

            if ($loanSale->getCompanyBuyerId() === \Glavfinans\Domain\Entity\Company::FINZ_ID) {
                $loan = $loanRepository->findByPK($id['loanId']);
                $loan->setCompanyId($loanSale->getCompanyBuyerId());

                if ($loan->getLoanStatusId()->isActive()) {
                    $countActive++;
                }
                $count++;

                $transaction->persist($loan);
                $transaction->run();
                echo "Обработан займ {$loan->getId()}" . PHP_EOL;
            }
        }

        echo "Активных займов $countActive" . PHP_EOL;
        echo "Общее число займов $count" . PHP_EOL;
        echo "Вот и все" . PHP_EOL;
    }

    /**
     * @param WriteOffDebtPartRepository $debtPartRepository
     * @param IncomingTransferRepositoryInterface $incomingTransferRepository
     * @return void
     */
    public function actionFixWriteOff(
        WriteOffDebtPartRepositoryInterface $debtPartRepository,
        IncomingTransferRepositoryInterface $incomingTransferRepository
    ): void {
        $debtParts = $debtPartRepository->findAll();

        /** @var \Glavfinans\Core\Entity\WriteOffDebtPart\WriteOffDebtPart $debtPart */
        foreach ($debtParts as $debtPart) {
            if (!$debtPart->getStatus()->isPayed()) {
                continue;
            }
            $incomingTransfers = $incomingTransferRepository->findAllByLoanId($debtPart->getLoanId());
            /** @var IncomingTransfer $incomingTransfer */
            $incomingsPercent = [];
            $isChanged = false;
            foreach ($incomingTransfers as $incomingTransfer) {
                if ($incomingTransfer->getSum()->isEqual($debtPart->getWriteOffSum())
                    && $incomingTransfer->getDestination()->isPercent()
                    && $incomingTransfer->getType() !== PaymentType::TYPE_WRITE_OFF_RESERVES) {
                    $incomingTransfer->setType(PaymentType::makeWriteOffReserves());
                    $incomingTransfer->setSystemId(uniqid());
                    $incomingTransfer->save(false, ['type', 'system_id']);
                    echo 'поменяли тип и систем айди у инкоминг трансфера ' . $incomingTransfer->getSystemId() . ' у займа #' . $incomingTransfer->getLoanId() . PHP_EOL;
                    $isChanged = true;
                    break;
                }

                if ($incomingTransfer->getDestination()->isPercent() && $incomingTransfer->getPaymentDate() >= $debtPart->getCreatedAt() && $incomingTransfer->getType() !== PaymentType::TYPE_WRITE_OFF_RESERVES) {
                    $incomingsPercent[] = $incomingTransfer;
                }

                if ($incomingTransfer->getDestination()->isPercent() && $incomingTransfer->getPaymentDate() >= $debtPart->getCreatedAt() && $incomingTransfer->getType() === PaymentType::TYPE_WRITE_OFF_RESERVES) {
                    $isChanged = true;
                    break;
                }
            }

            if (1 == count($incomingsPercent) && !$isChanged) {
                $transfer = $incomingsPercent[0];

                $transfer->setSum($transfer->getSum()->diff($debtPart->getWriteOffSum()));
                $transfer->save(false, ['sum']);

                $newTransfer = new IncomingTransfer();
                $newTransfer->setSum($debtPart->getWriteOffSum());
                $newTransfer->setDestination(PaymentDestination::makePercent());
                $newTransfer->setSystemId(uniqid());
                $newTransfer->setLoanId($transfer->getLoanId());
                $newTransfer->setLoanHistoryId($transfer->getLoanHistoryId());
                $newTransfer->setPaymentDate($transfer->getPaymentDate());
                $newTransfer->setCompanyId($transfer->getCompanyId());
                $newTransfer->setType(PaymentType::makeWriteOffReserves());
                $newTransfer->setStaffId($transfer->getStaffId());
                $newTransfer->setReturnItId($transfer->getReturnItId());
                $newTransfer->setInternalStatus($transfer->getInternalStatus());
                $newTransfer->setChannelId($transfer->getChannelId());
                $newTransfer->setRrn($transfer->getRrn());
                $newTransfer->setGwId($transfer->getGwId());
                $newTransfer->setCardNumber($transfer->getCardNumber());
                $newTransfer->setMode($transfer->getMode());
                $newTransfer->setRecurrent($transfer->getRecurrent());
                $newTransfer->setRecurrentId($transfer->getRecurrentId());
                $newTransfer->setOverdueDays($transfer->getOverdueDays());
                $newTransfer->setPaymentId($transfer->getPaymentId());
                $newTransfer->setCreationDate($transfer->getCreationDate());

                if ($newTransfer->save(false)) {
                    echo "разделили платежи у займа #" . $newTransfer->getLoanId() . PHP_EOL;
                }
            }
        }
    }

    /**
     * @return void
     */
    public function actionFillWmIdCityads()
    {
        $sql = <<<SQL
            SELECT * FROM `lead_click` 
                     WHERE `lead_provider_rate_id` IN (SELECT `id` FROM `lead_provider_rate` WHERE `lead_provider` = 'cityads')
                        AND `wm_id` IS NULL
SQL;
        /** @var LeadClick[] $leadClicks */
        $leadClicks = LeadClick::model()->findAllBySql($sql);
        $counter = 0;

        foreach ($leadClicks as $leadClick) {
            $jsonData = json_decode($leadClick->lead_data, true);
            if (!isset($jsonData['utm_campaign'])) {
                continue;
            }
            $wmId = $jsonData['utm_campaign'];
            $leadClick->wm_id = $wmId;
            $leadClick->save(false, ['wm_id']);
            $counter++;
            echo "Прошло {$counter}" . PHP_EOL;
        }
    }

    /**
     * Команда для исправления rrn в incoming transfer
     *
     * @param IncomingTransferRepositoryInterfaceEntity $incomingTransferRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFixRrnInIncomingTransfer(
        IncomingTransferRepositoryInterfaceEntity $incomingTransferRepository,
        TransactionInterface                      $transaction
    ): void {
        $limit = 1000;
        $offset = 0;

        do{
            $incomingTransfers = $incomingTransferRepository->findAllWithInvalidRrn($limit);
            $count = count($incomingTransfers);

            foreach ($incomingTransfers as $incomingTransfer) {
                $incomingTransfer->setRrn(null);
                $transaction->persist($incomingTransfer);
            }
            $transaction->run();

            $offset += min($limit, $count);
            echo "Прошло $offset" . PHP_EOL;
        }while ($count > 0);

        echo 'Ну вот и все' . PHP_EOL;
    }

    /**
     * Команда для исправления cardNumber в incoming transfer
     *
     * @param IncomingTransferRepositoryInterfaceEntity $incomingTransferRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionFixCardNumberInIncomingTransfer(
        IncomingTransferRepositoryInterfaceEntity $incomingTransferRepository,
        TransactionInterface                      $transaction
    ): void {
        $limit = 1000;
        $offset = 0;

        do{
            $incomingTransfers = $incomingTransferRepository->findAllWithInvalidCardNumber($limit);
            $count = count($incomingTransfers);

            foreach ($incomingTransfers as $incomingTransfer) {
                $incomingTransfer->setCardNumber(null);
                $transaction->persist($incomingTransfer);
            }
            $transaction->run();

            $offset += min($limit, $count);
            echo "Прошло $offset" . PHP_EOL;
        }while ($count > 0);

        echo 'Ну вот и все' . PHP_EOL;
    }

    /**
     *
     * Очистка очереди на получение исполнительного производства из ФССП
     * Необходимо, т.к. очищаем очередь, и запускаем новую по парсингу сайта, а там накопилось 130к записей
     *
     * @param FsspRequestRepositoryInterface $fsspRequestRepository
     * @param TransactionInterface $transaction
     * @return void
     * @throws Throwable
     */
    public function actionClearFsspStatus(
        FsspRequestRepositoryInterface $fsspRequestRepository,
        TransactionInterface           $transaction,
    ) {
        $i = 0;
        $chunk = 1000;
        $statusError = FsspRequestStatus::makeStatusError();

        do {
            $fsspRequestList = $fsspRequestRepository->findAllCreatedWithChunk(chunk: $chunk);
            if (empty($fsspRequestList)) {
                break;
            }

            /** @var FsspRequest $request */
            foreach ($fsspRequestList as $request) {
                $request->setStatus(status: $statusError);
                $transaction->persist(entity: $request);
            }
            $transaction->run();
            $i++;

            echo 'Processed ' . $i * $chunk . ' rows' . PHP_EOL;
        } while (true);

        echo 'Command completed' . PHP_EOL;
    }

    /**
     * Заполняет state_duty_id недостающим записям статусов
     *
     * Заполняет если ранее была оплачена госпошлина, а ее id статусам не привязался, хоты должен был
     */
    public function actionFillStateDutyId(ICourtCollectionHistoryRepository $courtCollectionHistoryRepository)
    {
        /** Все записи в таблице c незаполненной state_duty_id */
        $courtCollectionHistories = $courtCollectionHistoryRepository->hasNoStateDutyId();

        if (empty($courtCollectionHistories)) {
            echo 'Не найдено записей с пустым state_duty_id' . PHP_EOL;
            return;
        }

        /** Путь к файлу монолога */
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $fileName = $_SERVER['DOCUMENT_ROOT'] . '/headquarter/protected/runtime/log/idCCHWhereChangedStateDutyId/' .
                (new DateTimeImmutable())->format(DateFmt::DT_DB) . '.log';
        } else {
            $fileName = __DIR__ . '/../../../headquarter/protected/runtime/log/idCCHWhereChangedStateDutyId/' .
                (new DateTimeImmutable())->format(DateFmt::DT_DB) . '.log';
        }

        $handlers = [new StreamHandler($fileName)];
        $monolog = new Logger('id', $handlers);

        $count = 0;
        /** @var CourtCollectionHistory $courtHistory */
        foreach ($courtCollectionHistories as $courtHistory) {

            if ($courtHistory->getFirstCategory()->isDocInCourt()) {
                continue;
            }

            $lastDutyId = $courtCollectionHistoryRepository->findIdPrevPayedStateDuty(
                loanId: $courtHistory->getLoanId(),
                date: $courtHistory->getDate()
            );

            if (null !== $lastDutyId) { // если есть перед статусом оплаченная госпошлина
                $courtHistory->setStateDutyId(stateDutyId: $lastDutyId); // то это ее статус
                $courtHistory->save(runValidation: false, attributes: ['state_duty_id']);

                /** Сохраняем id измененных записей, дабы если, что могли откатить результат команды */
                $monolog->info("{$courtHistory->getId()}");

                echo 'Обновлена запись №' . $courtHistory->getId() . PHP_EOL;
                $count++;
            }
        }

        echo 'Найдено записей с пустым state_duty_id: ' . count(value: $courtCollectionHistories) . PHP_EOL . 'Всего обновлено: ' . $count . PHP_EOL;
    }

    /**
     * Закрывает сегмент Loan History у закрытых, отменённых займов
     *
     * @param LoanRepositoryInterfaceEntuty $loanRepository
     * @param EntityManagerInterface $entityManager
     * @return void
     *
     * ./yiic special CloseLHWhereLoanStatusDecline
     */
    public function actionCloseLHWhereLoanStatusDecline(
        LoanRepositoryInterfaceEntuty $loanRepository,
        EntityManagerInterface        $entityManager,
    ) {
        $loans = $loanRepository->findLoansWhereUnclosedLHByLoanStatus(loanStatusId: Status::makeCanceled());

        echo 'Найдено: ' . count($loans) . ' займа(ов) с не закрытым lh' . PHP_EOL;

        $count = 0;
        foreach ($loans as $loan) {

            $lh = $loan->getActiveLoanHistory();
            $lh->setActiveEnd(activeEnd: $loan->getCancelDate());
            $entityManager->persist(entity: $lh)->run();
            echo 'У займа: ' . $loan->getId() . ' закрыт сегмент lh' . PHP_EOL;
            $count++;
        }
        echo 'У ' . $count . ' займа(ов) закрыт сегмент lh' . PHP_EOL;
    }

    /**
     * Закрывает сегмент Loan History у выплаченных займов
     *
     * @param LoanRepositoryInterfaceEntuty $loanRepository
     * @param EntityManagerInterface $entityManager
     * @return void
     *
     * ./yiic special CloseLHWhereLoanStatusPayOff
     */
    public function actionCloseLHWhereLoanStatusPayOff(
        LoanRepositoryInterfaceEntuty $loanRepository,
        EntityManagerInterface        $entityManager,
    )
    {
        $loans = $loanRepository->findLoansWhereUnclosedLHByLoanStatus(loanStatusId: Status::makePayOff());

        echo 'Найдено: ' . count($loans) . ' займа(ов) с не закрытым lh' . PHP_EOL;

        $count = 0;
        foreach ($loans as $loan) {
            $lh = $loan->getActiveLoanHistory();
            $lh->setActiveEnd(activeEnd: $loan->getCloseDate());
            $entityManager->persist(entity: $lh)->run();
            echo 'У займа: ' . $loan->getId() . ' закрыт сегмент lh' . PHP_EOL;
            $count++;
        }
        echo 'У ' . $count . ' займа(ов) закрыт сегмент lh' . PHP_EOL;
    }

    /**
     * Заполнение  confirm_date в таблице sms_confirm только для модели client
     * при условии, что send_date не NULL
     *
     * @return void
     */
    public function actionFillConfirmDate(): void
    {
        $sql = <<<SQL
                    SELECT * FROM `gfk`.`sms_confirm` AS `sms_c`
                    WHERE `phone` IN (SELECT `login` FROM `gfk`.`client` AS `cl` 
                            JOIN `gfk`.`loan` AS `l` ON `cl`.`id` = `l`.`client_id`
                            WHERE `l`.`loan_status_id` != :loan_status_id)
                    AND `sms_c`.`send_date` IS NOT NULL
                    AND `sms_c`.`confirm_date` IS NULL
                    AND `sms_c`.`model` = :model
                    AND `sms_c`.`phone` IN (SELECT `sms`.`phone` FROM 
                                              (SELECT `gfk_sms`.`send_date`, `gfk_sms`.`phone` FROM `gfk_sms`.`sms` AS `gfk_sms` 
                                              WHERE `gfk_sms`.`status` = :status ) AS `sms`)
                    AND `sms_c`.`send_date` IN (SELECT `sms`.`send_date` FROM 
                                            (SELECT `gfk_sms`.`send_date`, `gfk_sms`.`phone` FROM `gfk_sms`.`sms` AS `gfk_sms` 
                                              WHERE `gfk_sms`.`status` = :status) AS `sms`);
SQL;
        $params = [
            ':loan_status_id' => Status::PAY_OFF,
            ':model' => 'client',
            ':status' => 'delivered'
        ];
        /** @var SmsConfirm[] $smsConfirm */
        $smsConfirms = SmsConfirm::model()->findAllBySql($sql, $params);
        $counter = 0;
        foreach ($smsConfirms as $smsConfirm) {
            $smsConfirm->confirm_date = $smsConfirm->send_date;
            $counter++;
            $smsConfirm->save(false, ['confirm_date']);
            echo "Выполнено $counter" . PHP_EOL;
        }
        echo "Всего найдено " . count($smsConfirms) . " записей без confirm_date. " . PHP_EOL . "Обработано $counter записей." . PHP_EOL;
    }

    /**
     * Устанавливает пол для сотрудников у которых есть запись в поле fio
     *
     * @return void
     */
    public function actionSetSexFromStaff(): void
    {
        $sql = <<<SQL
                    SELECT * FROM `staff` 
                    WHERE `fio` IS NOT NULL    
                    AND `fio` != '';
SQL;
        $staffs = Staff::model()->findAllBySql($sql);
        $count = 0;

        foreach ($staffs as $staff) {
            if (!$staff->getSex()->isNotSelected()) {
                continue;
            }

            $fio = $staff->getFIO();
            $gender = Gender::makeByFio($fio);
            if ($gender->isNotSelected()) {
                echo "У сотрудника {$staff->getId()} не удалось определить корректный пол";
                continue;
            }

            $staff->setSex($gender);
            if (!$staff->save(false, ['sex'])) {
                echo "Не удалось сохранить пол у сотрудника {$staff->getId()}";
            }

            $count++;
            echo "Сотруднику {$staff->getId()} присвоен пол {$staff->getSex()}" . PHP_EOL;
        }
        echo "Выполнилось успешно $count" . PHP_EOL . 'Всего' . count($staffs) . PHP_EOL;
    }

    /**
     * Устанавливает ФИО для сотрудников по ID
     *
     * @return void
     */
    public function actionFillFioForStaff(): void
    {
        $staffsFio = [
            'Какунин Дмитрий' => 'Какунин Дмитрий Сергеевич',
            'Соловьēв Павел' => 'Соловьев Павел Алексеевич',
            'Руководитель службы защиты активов' => 'Шаблинский Кирилл Эдуардович',
            'Белоглазова Татьяна' => 'Белоглазова Татьяна Алексеевна',
            'Ракицкий Степан' => 'Ракицкий Степан Степанович',
        ];

        $sql = <<<SQL
                    SELECT * FROM `staff` WHERE `name` = :name 
SQL;
        $count = 0;
        foreach ($staffsFio as $name => $fio) {
            $params = [':name' => $name];
            $staff = Staff::model()->findBySql($sql, $params);
            $staff->fio = $fio;

            if (!$staff->save(false, ['fio'])) {
                echo "Не удалось записать ФИО для сотрудника #$name}" . PHP_EOL;
                continue;
            }

            echo "Сотруднику #$name присвоено ФИО $fio" . PHP_EOL;
            $count++;
        }
        echo "Успешно выполнилось $count записей" . PHP_EOL . 'Всего записей ' . count($staffsFio) . PHP_EOL;
    }

    /**
     * Изменение статуса для primo на неактивный
     *
     * @return void
     */
    public function actionFillStatusNoActiveFromPrimo(): void
    {
        $primoIds = ['Primo Collect', 'Primo Collect Finza'];

        $sql = <<<SQL
                    SELECT * FROM `staff` WHERE `name` = :name 
SQL;
        $count = 0;

        foreach ($primoIds as $primoName) {
            $params = [':name' => $primoName];
            $staff = Staff::model()->findBySql($sql, $params);
            $staff->active = 0;

            if (!$staff->save(false, ['active'])) {
                echo "Не удалось изменить статус активности для primo c id#$primoName" . PHP_EOL;
                continue;
            }
            echo "Статус  активности для primo c id#$primoName успешно изменен на 0" . PHP_EOL;
            $count++;
        }
        echo "Успешно измененных статусов $count" . PHP_EOL . 'Всего записей ' . count($primoIds) . PHP_EOL;
    }

    /**
     * @return void
     */
    public function actionAddAddressSza(): void
    {
        $addressDadata = new AddressByDadata('350000, г. Краснодар, ул. Гимназическая, д. 49, офис 104');
        $address = Address::initAddress($addressDadata);
        $company = Company::model()->findByPk(10);
        $company->address_id = $address->getId();
        $company->save(false, ['address_id']);
    }

    /**
     * @param LoanRepositoryInterfaceEntuty $loanRepository
     * @param EntityManagerInterface $entityManager
     *
     * @return void
     */
    public function actionFillInternalStatusFromPrevLoan(
        LoanRepositoryInterfaceEntuty $loanRepository,
        EntityManagerInterface        $entityManager,
    ): void {
        $offset = 0;
        $limit = 100;

        do {
            $loans = $loanRepository->findWithLimitAndOffset($limit, $offset, ['prevLoanId' => ['!=' => null]], ['prevLoan']);

            foreach ($loans as $loan) {
                if (null !== $loan->getInternalStatus()) {
                    continue;
                }

                $loan->setInternalStatus($loan->getPrevLoan()->getInternalStatus());
                $entityManager->persist($loan);
            }

            $entityManager->run();
            $offset += min($limit, count($loans));
            echo "Поменяли статус у $offset займов" . PHP_EOL;
        } while (!empty($loans));

        echo 'Закончили' . PHP_EOL;
    }

    /**
     * @return void
     */
    public function actionFixSaleDocsSza(): void
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('header = "Уведомление о состоявшейся уступке прав требования"');
        $criteria->addCondition('DATE(load_date) BETWEEN "2022-11-24" AND "2022-11-26"');
        $messages = ClientMessage::model()->findAll($criteria);
        $count = 0;

        foreach ($messages as $message) {
            $message->setHeader('Уступка права требования');
            $message->save(false, ['header']);
            $count++;
            echo "Поменяли тему по займу {$message->getLoanId()}" . PHP_EOL;
        }

        echo "Закончили. Поменяли $count" . PHP_EOL;
    }

    /**
     * @param LoanRepositoryInterfaceEntuty $loanRepository
     * @param EntityManagerInterface $entityManager
     *
     * @return void
     */
    public function actionDeleteCloseLoanCch(
        LoanRepositoryInterfaceEntuty $loanRepository,
        EntityManagerInterface        $entityManager,
    ): void {
        $limit = 100;
        $offset = 0;
        $count = 0;
        $saleDate = new DateTimeImmutable('2022-11-24 00:00:00');
        do{
            $loans = $loanRepository->findWithLimitAndOffset(
                $limit,
                $offset, [
                'prev_loan_id' => ['!=' => null],
            ],
                ['prevLoan']);
            foreach ($loans as $loan) {
                $loan = $loan->getPrevLoan();
                /** @var \Glavfinans\Domain\Entity\CourtCollectionHistory|false $cch */
                $cch = $loan->getCourtCollectionHistories()->filter(function (\Glavfinans\Domain\Entity\CourtCollectionHistory $courtCollectionHistory) use ($saleDate) {
                    return null === $courtCollectionHistory->getDeletedAt() && $courtCollectionHistory->getCreatedAt() > $saleDate && $courtCollectionHistory->getSecondCategory()?->isLoanClosed();
                })->last();

                if (false === $cch) {
                    continue;
                }

                $cch->setDeletedAt(new DateTimeImmutable());
                $entityManager->persist($cch);
                $count++;
            }
            $entityManager->run();
            $entityManager->clean(true);

            $offset += min($limit, count($loans));
            if ($count > 0) {
                echo "Обработали $count" . PHP_EOL;
            }
            echo "Прошло $offset" . PHP_EOL;
        } while (!empty($loans));

        echo "Закончили, обработано $count" . PHP_EOL;
    }

    /**
     * @param LoanRepositoryInterfaceEntuty $loanRepository
     * @param EntityManagerInterface $entityManager
     *
     * @return void
     */
    public function actionMoveSzaCchToPrevLoan(
        LoanRepositoryInterfaceEntuty $loanRepository,
        EntityManagerInterface        $entityManager,
    ): void {
        $limit = 500;
        $offset = 0;
        $count = 0;
        do{
            $loans = $loanRepository->findWithLimitAndOffset(
                $limit,
                $offset, [
                'prev_loan_id' => ['!=' => null],
            ],
                ['prevLoan']);
            foreach ($loans as $loan) {
                $loan->getCourtCollectionHistories()->map(function (\Glavfinans\Domain\Entity\CourtCollectionHistory $courtCollectionHistory) use ($entityManager, $loan, &$count) {
                    if (null === $loan->getPrevLoanId()) {
                        return;
                    }

                    $courtCollectionHistory->setLoanId($loan->getPrevLoanId());
                    $count += 1;
                    $entityManager->persist($courtCollectionHistory);
                });
            }
            $entityManager->run();
            $entityManager->clean(true);

            $offset += min($limit, count($loans));
            if ($count > 0) {
                echo "Обработали $count" . PHP_EOL;
            }
            echo "Прошло $offset" . PHP_EOL;
        } while (!empty($loans));

        echo "Закончили, обработано $count" . PHP_EOL;
    }

    /**
     * Команда для проставления гос пошлины по иску в рамках задачи GFK-4373
     *
     * @param ICourtCollectionHistoryRepository $historyRepository
     * @param CourtCollectionHistoryService $collectionHistoryService
     * @return void
     * @throws Exception
     */
    public function actionPutTheFlagPaymentStateDutyClaim(
        ICourtCollectionHistoryRepository $historyRepository,
        CourtCollectionHistoryService     $collectionHistoryService,
    ): void {
        try {
            $errors = '';
            $transaction = InnerTransaction::begin();

            $arrayLoanIdsNoFlag = $collectionHistoryService
                ->getLoanIdsNoFlagPaymentStateDutyClaim(historyRepository: $historyRepository);

            foreach ($arrayLoanIdsNoFlag as $history) {
                $collectionHistory = new CourtCollectionHistory();
                $collectionHistory->setLoanId(loanId: $history['loan_id']);
                $collectionHistory->setClientId(clientId: $history['client_id']);
                $collectionHistory->setStaffId(staffId: $history['staff_id']);
                $collectionHistory->setFirstCategory(category: CourtCollectionCategoryValueType::makePaymentStateDutyClaim());
                $collectionHistory->setDate(date: DateTime::createFromFormat(
                    format: DateFmt::DT_DB,
                    datetime: $history['date'])
                );
                if (!$collectionHistory->save(false)) {
                    $errors = $errors . 'Не удалось сохранить# ' .
                        $collectionHistory->getErrorsString();
                }
            }

            if (empty($errors)) {
                echo "Статусы успешно проставлены!\n";
                $transaction->commit();
            } else {
                echo $errors . ' ';
                $transaction->rollback();
            }
        } catch (Exception $e) {
            echo $e->getMessage();

            $transaction->rollback();
        }
    }
}
