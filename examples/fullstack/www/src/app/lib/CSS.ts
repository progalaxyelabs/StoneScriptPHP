export class CSS {
    name: string
    style: any

    constructor(name: string) {
        this.name = name
        this.style = { }
    }

    toString(): string {
        let str = this.name + ' { '
        for(const property of this.style) {
            str += `${property}: ${this.style[property]}; `
        }
        str += '}'
        return str
    }
}