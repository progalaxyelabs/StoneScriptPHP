export class Item {
    constructor(
        public title = '',
        public description = '',
        public originalPrice = 0,
        public discountType = 0,
        public discountValue = 0,
        public image = '',
        public availableQuantity = 0,
        public categoryIds: number[] = [],
        public dateCreated = 0,
        public size?: string
    ) {

    }

}