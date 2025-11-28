import { ComponentFixture, TestBed } from '@angular/core/testing';

import { Ecomm1Component } from './ecomm1.component';

describe('Ecomm1Component', () => {
  let component: Ecomm1Component;
  let fixture: ComponentFixture<Ecomm1Component>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Ecomm1Component]
    })
    .compileComponents();
    
    fixture = TestBed.createComponent(Ecomm1Component);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
