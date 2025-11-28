import { CommonModule } from '@angular/common';
import { Component, ElementRef, HostListener, ViewChild } from '@angular/core';
import { RouterModule } from '@angular/router';

class SlidingArray {
    private indexes: number[] = []
    // private forwardPointer
    // private reversePointer
    private currentPointer
    constructor(private numImages: number) {
        for (let i = 0; i < numImages; i++) {
            this.indexes.push(i)
        }

        // this.forwardPointer = 0
        // this.reversePointer = numImages - 1
        this.currentPointer = 0
    }

    current(): number[] {
        let pointer = this.currentPointer

        const a: number[] = []
        let i = 0

        a.push(this.indexes[pointer])

        for (i = 0; i < 3; i++) {
            pointer++
            if (pointer === this.numImages) {
                pointer = 0
            }
            a.push(this.indexes[pointer])
        }

        pointer = this.currentPointer
        for (i = 0; i < 3; i++) {
            pointer--
            if (pointer === -1) {
                pointer = this.numImages - 1
            }
            a.unshift(this.indexes[pointer])
        }

        console.log('SlidingArray is ', a)

        return a
    }

    next(): number[] {
        this.currentPointer++
        if (this.currentPointer === this.numImages) {
            this.currentPointer = 0
        }
        return this.current()
    }

    previous(): number[] {
        this.currentPointer--
        if (this.currentPointer === -1) {
            this.currentPointer = this.numImages - 1
        }
        return this.current()
    }
}

type SlideStruct = {
    image: string,
    url: string
}

enum DIRECTION {
    LEFT,
    RIGHT,
    NEITHER,
}


@Component({
    selector: 'app-carousel1',
    imports: [CommonModule, RouterModule],
    templateUrl: './carousel1.component.html',
    styleUrl: './carousel1.component.css',
    providers: []
})
export class Carousel1Component {
    carouselViewportWidth = window.innerWidth
    slideWidth = window.innerWidth * 0.6
    transform = 'translate(0)'
    transitionDuration = '0s'

    isDragging = false
    pointerStartingClientX = 0
    previousClientX = 0
    slideContainerPosition = 0
    slideContainerStartLimit = 0
    slideContainerEndLimit = 0
    slideContainerOffset = 0
    dragDirection: DIRECTION = DIRECTION.NEITHER
    isLoop = false
    directionText = ''

    @ViewChild('slideContainer')
    slideContainer!: ElementRef

    @ViewChild('carousel')
    carousel!: ElementRef


    @HostListener('window:resize', ['e'])
    handleWindowResize(event: Event) {
        this.updateSlideWidth()
    }

    images: SlideStruct[] = [
        { image: '/assets/ecomm1/1024x360-1.jpeg', url: '/ecomm1/search?q=category1' },
        { image: '/assets/ecomm1/1024x360-2.jpeg', url: '/ecomm1/search?q=category2' },
        { image: '/assets/ecomm1/1024x360-3.jpeg', url: '/ecomm1/search?q=category3' },
        { image: '/assets/ecomm1/1024x360-4.jpeg', url: '/ecomm1/search?q=category4' },
        { image: '/assets/ecomm1/1024x360-5.jpeg', url: '/ecomm1/search?q=category5' },
    ]

    carouselSlides: SlideStruct[] = []

    slidingArray: SlidingArray

    currentSlide = 1
    slidePositions = [0, 0, 0, 0, 0, 0, 0]
    messageId = 1

    constructor() {
        this.slidingArray = new SlidingArray(this.images.length)

        setTimeout(() => {
            this.resetSlides(this.slidingArray.current())
            this.updateSlideWidth()
            this.setCenterSlidePosition()
        }, 1)

    }

    resetSlides(indexes: number[]) {
        console.log('resetSlides ', indexes)
        this.carouselSlides.length = 0
        for (let i = 0; i < indexes.length; i++) {
            this.carouselSlides.push(this.images[indexes[i]])
        }
        console.log('carouselSlides ', this.carouselSlides)
    }


    updateSlideWidth() {
        this.carouselViewportWidth = (this.slideContainer.nativeElement as HTMLDivElement)
            .getBoundingClientRect().width

        this.slideWidth = this.carouselViewportWidth * 0.6


        this.slideContainerOffset = (this.carouselViewportWidth / 2) + (this.slideWidth / 2)
        this.slideContainerStartLimit = this.slideContainerOffset - this.slideWidth
        this.slideContainerEndLimit = this.slideContainerOffset - (this.carouselSlides.length * this.slideWidth)
    }

    setCenterSlidePosition() {
        this.currentSlide = Math.floor((this.carouselSlides.length + 1) / 2)
        if (this.currentSlide === 0) {
            return
        }
        this.moveToCurrentSlide(false)
    }

    showPrevious(e: Event | null) {
        if (this.currentSlide === 1) {
            return
        }

        this.currentSlide = this.currentSlide - 1

        this.moveToCurrentSlide(true)
    }

    showNext(e: Event | null) {
        if (this.currentSlide === (this.carouselSlides.length)) {
            return
        }

        this.currentSlide = this.currentSlide + 1

        this.moveToCurrentSlide(true)
    }

    moveToCurrentSlide(animate: boolean, duration = 0.5) {
        const pos = this.slideContainerOffset - (this.currentSlide * this.slideWidth)
        this.slideContainerPosition = Math.round(pos * 10) / 10
        console.log('slide container start: ' + this.slideContainerPosition + (animate ? ' with animation' : ''))
        this.transform = `translate(${this.slideContainerPosition}px)`
        if (animate) {
            this.transitionDuration = duration + 's'
        } else {
            this.transitionDuration = '0s'
        }
    }

    // onTouchStart(e: TouchEvent) {
    //     this.dragStart(e, e.changedTouches[0].clientX)
    // }
    // onTouchMove(e: TouchEvent) {
    //     this.drag(e, e.changedTouches[0].clientX)
    // }
    // onTouchEnd(e: TouchEvent) {
    //     this.dragEnd(e, e.changedTouches[0].clientX)
    //     console.log(this.messageId++, e)
    // }
    // onTouchCancel(e: TouchEvent) {
    //     this.dragEnd(e, e.changedTouches[0].clientX)
    //     console.log(this.messageId++, e)
    // }


    onPointerDown(e: PointerEvent) {
        this.dragStart(e)
        console.log(this.messageId++, e)
    }
    onPointerMove(e: PointerEvent) {
        this.drag(e)
    }
    onPointerUp(e: PointerEvent) {
        this.dragEnd(e)
        console.log(this.messageId++, e)
    }
    onPointerCancel(e: PointerEvent) {
        this.dragEnd(e)
        console.log(this.messageId++, e)
    }
    // onPointerOut(e: PointerEvent) {
    //     this.dragEnd(e, e.clientX)
    //     console.log(this.messageId++, e)
    // }

    rearrangeSlides() {
        let c = this.slideContainer.nativeElement as HTMLDivElement
        if (this.currentSlide === 5) {
            c.appendChild(c.firstElementChild!)
            this.slideContainerPosition += this.slideWidth
            this.transform = `translate(${this.slideContainerPosition}px)`
            this.currentSlide = 4
            this.resetSlides(this.slidingArray.next())
        } else if (this.currentSlide === 3) {
            c.prepend(c.lastElementChild!)
            this.slideContainerPosition -= this.slideWidth
            this.transform = `translate(${this.slideContainerPosition}px)`
            this.currentSlide = 4
            this.resetSlides(this.slidingArray.previous())
        }
        this.transitionDuration = '0s'
    }

    onSlideClick(e: Event) {
        e.preventDefault();
        e.stopImmediatePropagation()
        e.stopPropagation()
    }

    dragStart(e: PointerEvent) {
        e.preventDefault()
        this.pointerStartingClientX = e.clientX
        this.previousClientX = e.clientX
        this.isDragging = true
        // console.log(this.messageId++, e)
        console.log('Drag start', this.pointerStartingClientX)
        this.dragDirection = DIRECTION.NEITHER
        this.directionText = 'NEITHER'

        this.rearrangeSlides()
    }


    dragEnd(e: PointerEvent) {
        this.isDragging = false
        this.pointerStartingClientX = 0
        // console.log(this.messageId++, e)
        console.log('Drag end', e.clientX)
        this.adjustSlideInView()
        this.dragDirection = DIRECTION.NEITHER
        this.directionText = 'NEITHER'
    }

    drag(e: PointerEvent) {
        if (!this.isDragging) {
            return
        }

        e.preventDefault()
        const movedDistance = e.clientX - this.previousClientX
        const pos = this.slideContainerPosition + movedDistance
        const posRounded = Math.round(pos * 10) / 10
        if (
            (posRounded > this.slideContainerStartLimit)
            || (posRounded < this.slideContainerEndLimit)
        ) {
            return
        }

        this.slideContainerPosition = posRounded
        this.previousClientX = e.clientX
        this.dragDirection = ((this.pointerStartingClientX > e.clientX) ? DIRECTION.LEFT : DIRECTION.RIGHT) as DIRECTION
        this.directionText = ((this.dragDirection === DIRECTION.NEITHER) ? 'NEITHER' : (this.dragDirection === DIRECTION.LEFT ? 'LEFT' : 'RIGHT'))

        this.transform = `translate(${this.slideContainerPosition}px)`
        this.transitionDuration = '0s'

        // console.log(this.messageId++, e)
        // console.log('Dragging', this.slideContainerPosition)
    }

    adjustSlideInView() {
        switch (this.dragDirection) {
            case DIRECTION.LEFT:
                this.showNext(null)
                this.directionText = 'LEFT'
                break
            case DIRECTION.RIGHT:
                this.showPrevious(null)
                this.directionText = 'RIGHT'
                break
        }
    }

}
