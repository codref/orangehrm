<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */

namespace OrangeHRM\Leave\Api;

use DateTime;
use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\ParameterBag;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\ResourceEndpoint;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\Service\DateTimeHelperTrait;
use OrangeHRM\Core\Traits\Service\NormalizerServiceTrait;
use OrangeHRM\Entity\Leave;
use OrangeHRM\Leave\Api\Model\LeaveBalanceModel;
use OrangeHRM\Leave\Api\Model\LeavePeriodModel;
use OrangeHRM\Leave\Api\Traits\LeaveRequestParamHelperTrait;
use OrangeHRM\Leave\Dto\LeavePeriod;
use OrangeHRM\Leave\Service\LeaveApplicationService;
use OrangeHRM\Leave\Traits\Service\LeaveEntitlementServiceTrait;

class LeaveBalanceAPI extends Endpoint implements ResourceEndpoint
{
    use LeaveRequestParamHelperTrait;
    use LeaveEntitlementServiceTrait;
    use NormalizerServiceTrait;
    use DateTimeHelperTrait;
    use AuthUserTrait;

    private ?LeaveApplicationService $leaveApplicationService = null;

    /**
     * @return LeaveApplicationService
     */
    protected function getLeaveApplicationService(): LeaveApplicationService
    {
        if (!$this->leaveApplicationService instanceof LeaveApplicationService) {
            $this->leaveApplicationService = new LeaveApplicationService();
        }
        return $this->leaveApplicationService;
    }

    /**
     * @return int
     */
    protected function getLeaveTypeIdParam(): int
    {
        return $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_ATTRIBUTE,
            LeaveCommonParams::PARAMETER_LEAVE_TYPE_ID
        );
    }

    /**
     * @return DateTime|null
     */
    protected function getFromDateParam(): ?DateTime
    {
        return $this->getRequestParams()->getDateTimeOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            LeaveCommonParams::PARAMETER_FROM_DATE
        );
    }

    /**
     * @return DateTime|null
     */
    protected function getToDateParam(): ?DateTime
    {
        return $this->getRequestParams()->getDateTimeOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            LeaveCommonParams::PARAMETER_TO_DATE
        );
    }

    /**
     * @param string $key
     * @param array|null $default
     * @return array|null
     */
    protected function getDurationParam(string $key, ?array $default = null): ?array
    {
        return $this->getRequestParams()->getArrayOrNull(RequestParams::PARAM_TYPE_QUERY, $key, $default);
    }

    /**
     * @return string|null
     */
    protected function getPartialOptionParam(): ?string
    {
        return $this->getRequestParams()->getStringOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            LeaveCommonParams::PARAMETER_PARTIAL_OPTION
        );
    }

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResult
    {
        $empNumber = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_QUERY,
            CommonParams::PARAMETER_EMP_NUMBER,
            $this->getAuthUser()->getEmpNumber()
        );

        $leaveTypeId = $this->getLeaveTypeIdParam();
        $startDate = $this->getFromDateParam();
        $endDate = $this->getToDateParam();

        $leaveByPeriods = [];
        if ($startDate instanceof DateTime & $endDate instanceof DateTime) {
            $leaveByPeriods = $this->getLeaveBreakdownForAppliedDateRange($empNumber, $leaveTypeId, $startDate);
        }

        if (!empty($leaveByPeriods)) {
            $result = $this->getNormalizedLeaveBalanceResult($leaveByPeriods, $empNumber, $leaveTypeId);
        } else {
            $asAtDate = $startDate ?? $this->getDateTimeHelper()->getNow();
            $balance = $this->getLeaveEntitlementService()->getLeaveBalance(
                $empNumber,
                $leaveTypeId,
                $asAtDate,
                $endDate ?? null
            );

            $result = [
                'balance' => $this->getNormalizerService()->normalize(LeaveBalanceModel::class, $balance),
            ];
        }

        return new EndpointResourceResult(
            ArrayModel::class, $result,
            new ParameterBag([CommonParams::PARAMETER_EMP_NUMBER => $empNumber])
        );
    }

    /**
     * @param array $leaveByPeriods
     * @param int $empNumber
     * @param int $leaveTypeId
     * @return array {
     *     negative: true,
     *     breakdown: [
     *         {
     *             period: {
     *                 startDate: 2021-01-01,
     *                 endDate: 2021-12-31,
     *             },
     *             balance: {
     *                 entitled: 4,
     *                 used: 3.5,
     *                 scheduled: 0,
     *                 pending: 3,
     *                 taken: 0.5,
     *                 balance: 0.5,
     *                 asAtDate: 2021-08-17,
     *                 endDate: 2021-12-31,
     *             },
     *             leaves: [
     *                 {
     *                     balance: -0.5,
     *                     date: 2021-08-17,
     *                     length: 1,
     *                     status: null,
     *                 },
     *                 {
     *                     balance: -0.5,
     *                     date: 2021-08-18,
     *                     length: 0,
     *                     status: {
     *                         key: 5,
     *                         name: 'Holiday',
     *                     },
     *                 },
     *             ],
     *         }
     *     ]
     * }
     */
    private function getNormalizedLeaveBalanceResult(array $leaveByPeriods, int $empNumber, int $leaveTypeId): array
    {
        $negativeBalance = false;
        foreach ($leaveByPeriods as $leavePeriodIndex => $leavePeriod) {
            $days = $leavePeriod['days'];

            $firstDayInPeriod = ($leavePeriod['period'])->getStartDate();
            $lastDayInPeriod = ($leavePeriod['period'])->getEndDate();
            $dayKeys = array_keys($days);
            $firstDay = array_shift($dayKeys);
            if ($firstDay) {
                $firstDayInPeriod = new DateTime($firstDay);
            }
            $lastDay = array_pop($dayKeys);
            if ($lastDay) {
                $lastDayInPeriod = new DateTime($lastDay);
            }

            $leaveBalanceObj = $this->getLeaveEntitlementService()
                ->getLeaveBalance($empNumber, $leaveTypeId, $firstDayInPeriod, $lastDayInPeriod);

            $leaveByPeriods[$leavePeriodIndex]['balance'] = $this->getNormalizerService()
                ->normalize(LeaveBalanceModel::class, $leaveBalanceObj);
            $leaveByPeriods[$leavePeriodIndex]['period'] = $this->getNormalizerService()
                ->normalize(LeavePeriodModel::class, $leaveByPeriods[$leavePeriodIndex]['period']);

            $leaveBalance = $leaveBalanceObj->getBalance();
            foreach ($days as $date => $leaveDate) {
                $leaveDateLength = $leaveDate['length'];
                if ($leaveDateLength > 0) {
                    $leaveBalance -= $leaveDateLength;
                }
                $leaveByPeriods[$leavePeriodIndex]['leaves'][] = [
                    'balance' => $leaveBalance,
                    'date' => $date,
                    'length' => $leaveDate['length'],
                    'status' => $leaveDate['status'],
                ];
            }
            unset($leaveByPeriods[$leavePeriodIndex]['days']);

            if ($leaveBalance < 0) {
                $negativeBalance = true;
            }
        }

        return [
            'negative' => $negativeBalance,
            'breakdown' => $leaveByPeriods,
        ];
    }

    /**
     * @param int $empNumber
     * @param int $leaveTypeId
     * @param DateTime $startDate
     * @return array {
     *     period: LeavePeriod,
     *     days: {
     *         2021-08-19: {
     *             length: 1,
     *             status: null,
     *         },
     *         2021-08-20: {
     *             length: 1,
     *             status: {
     *                 key: 5,
     *                 name: 'Holiday',
     *             },
     *         },
     *         2021-08-21: {
     *             length: 1,
     *             status: {
     *                 key: 4,
     *                 name: 'Weekend',
     *             },
     *         },
     *     }
     * }
     */
    private function getLeaveBreakdownForAppliedDateRange(int $empNumber, int $leaveTypeId, DateTime $startDate): array
    {
        $leaveRequestParams = $this->getLeaveRequestParams($empNumber);
        $leaveDays = $this->getLeaveApplicationService()
            ->createLeaveObjectListForAppliedRange($leaveRequestParams);
        $holidays = [Leave::LEAVE_STATUS_LEAVE_WEEKEND, Leave::LEAVE_STATUS_LEAVE_HOLIDAY];

        $currentLeavePeriod = $this->getLeavePeriod($empNumber, $leaveTypeId, $startDate);
        $leavePeriodIndex = 0;
        $leaveByPeriods[$leavePeriodIndex] = [
            'period' => $currentLeavePeriod,
            'days' => []
        ];

        foreach ($leaveDays as $leave) {
            $leaveDate = $leave->getDate();

            // Get next leave period if request spans leave periods.
            if ($leaveDate > ($leaveByPeriods[$leavePeriodIndex]['period'])->getEndDate()) {
                $currentLeavePeriod = $this->getLeavePeriod($empNumber, $leaveTypeId, $leaveDate);
                $leavePeriodIndex++;
                $leaveByPeriods[$leavePeriodIndex] = [
                    'period' => $currentLeavePeriod,
                    'days' => []
                ];
            }

            if (in_array($leave->getStatus(), $holidays)) {
                $length = 0;
                $status = [
                    'key' => $leave->getStatus(),
                    'name' => $leave->getDecorator()->getLeaveStatus(),
                ];
            } else {
                $length = $leave->getLengthDays();
                $status = null;
            }

            $leaveByPeriods[$leavePeriodIndex]['days'][$this->getDateTimeHelper()->formatDateTimeToYmd($leaveDate)] = [
                'length' => $length,
                'status' => $status,
            ];
        }

        return $leaveByPeriods;
    }

    /**
     * @param int $empNumber
     * @param int $leaveTypeId
     * @param DateTime $date
     * @return LeavePeriod|null
     */
    protected function getLeavePeriod(int $empNumber, int $leaveTypeId, DateTime $date): ?LeavePeriod
    {
        $strategy = $this->getLeaveEntitlementService()->getLeaveEntitlementStrategy();
        return $strategy->getLeavePeriod($date, $empNumber, $leaveTypeId);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        $paramRules = $this->getCommonBodyParamRuleCollection();
        $paramRules->removeParamValidation(LeaveCommonParams::PARAMETER_COMMENT);
        $paramRules->addParamValidation(
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(CommonParams::PARAMETER_EMP_NUMBER, new Rule(Rules::IN_ACCESSIBLE_EMP_NUMBERS))
            )
        );
        if (!$this->getRequest()->getQuery()->has(LeaveCommonParams::PARAMETER_TO_DATE)) {
            $paramRules->addParamValidation(
                $this->getValidationDecorator()->notRequiredParamRule(
                    $paramRules->removeParamValidation(LeaveCommonParams::PARAMETER_FROM_DATE)
                )
            );
        }
        if (!$this->getRequest()->getQuery()->has(LeaveCommonParams::PARAMETER_FROM_DATE)) {
            $paramRules->addParamValidation(
                $this->getValidationDecorator()->notRequiredParamRule(
                    $paramRules->removeParamValidation(LeaveCommonParams::PARAMETER_TO_DATE)
                )
            );
        }
        return $paramRules;
    }

    /**
     * @inheritDoc
     */
    public function update(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }
}
