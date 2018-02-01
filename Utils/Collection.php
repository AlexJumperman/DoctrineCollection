<?php

namespace AlexJumperman\DoctrineCollectionBundle\Utils;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\ClosureExpressionVisitor;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;

class Collection extends ArrayCollection
{
    /**
     * @param array $params
     * @return static
     */
    public function findBy(array $params)
    {
        return $this->filter(function ($entity) use ($params) {
            foreach ($params as $param => $value) {

                $checkedValue = $this->getCheckedValue($entity, $param);

                if (is_array($value)) {
                    if (!in_array($checkedValue, $value)) {
                        return false;
                    }
                } else {
                    if ($checkedValue != $value) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    public function pluck($param, $unique = false)
    {
        $array = $this->map(function($entity) use ($param){
            return $this->getCheckedValue($entity, $param);
        });

        if($unique){
            return array_values(array_unique($array->getValues()));
        }

        return $array->getValues();
    }

    public function max($param)
    {
        return max($this->pluck($param));
    }

    public function min($param)
    {
        return min($this->pluck($param));
    }

    public function sum($param)
    {
        return array_sum($this->pluck($param));
    }

    public function average($param)
    {
        return array_sum($this->pluck($param)) / $this->count();
    }

    public function dump()
    {
        $normalizer = new GetSetMethodNormalizer();
        $callbackArray = [];
        foreach ($this->getPropertyList() as $prop){
            $callbackArray[$prop] = $this->getDumpCallback();
        }
        $normalizer->setCallbacks($callbackArray);
        $serializer = new Serializer([$normalizer]);
        return $serializer->normalize($this->toArray());
    }

    public function keys()
    {
        $singleElement = $this->first();
        if(is_array($singleElement)){
            return array_keys($singleElement);
        }
        return $this->getPropertyList();
    }

    public function sort(array $params)
    {
        return $this->matching(Criteria::create()->orderBy($params));
    }

    /**
     * {@inheritDoc}
     */
    public function matching(Criteria $criteria)
    {
        $expr     = $criteria->getWhereExpression();
        $filtered = $this->toArray();

        if ($expr) {
            $visitor  = new ClosureExpressionVisitor();
            $filter   = $visitor->dispatch($expr);
            $filtered = array_filter($filtered, $filter);
        }

        if ($orderings = $criteria->getOrderings()) {
            $next = null;
            foreach (array_reverse($orderings) as $field => $ordering) {
                $next = ClosureExpressionVisitor::sortByField($field, $ordering == Criteria::DESC ? -1 : 1, $next);
            }

            uasort($filtered, $next);
        }

        $offset = $criteria->getFirstResult();
        $length = $criteria->getMaxResults();

        if ($offset || $length) {
            $filtered = array_slice($filtered, (int)$offset, $length);
        }

        return new static($filtered);
    }

    protected function getDumpCallback()
    {
        return function ($object) {
            if($object instanceof \DateTime){
                return $object->format('Y-m-d H:i:s');
            };
            if(is_object($object)){
                if(method_exists($object, 'getId')){
                    return $object->getId();
                }
                return get_class($object);
            }
            return $object;
        };
    }

    private function getCheckedValue($entity, $param)
    {
        if(is_array($entity)){
            return $entity[$param];
        }

        if(array_key_exists($param, get_object_vars($entity))){
            return $entity->$param;
        }

        $method = 'get' . $param;
        if(method_exists($entity, $method)){
            if(is_object($entity->$method())){
                return $entity->$method()->getId();
            }
            else{
                return $entity->$method();
            }
        }

        throw new \Exception('Param ' . $param . ' does not exist');
    }

    private function getPropertyList()
    {
        $reflect = new \ReflectionClass($this->first());
        $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE);

        $propertyList = [];
        foreach ($props as $prop) {
            $propertyList[] = $prop->getName();
        }
        return $propertyList;
    }
}